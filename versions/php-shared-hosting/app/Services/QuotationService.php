<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Id;
use PDO;
use RuntimeException;

/**
 * Quotation approval → Order conversion, ported from the original's
 * lib/quotation-conversion.ts:
 *  - approving a quotation creates its Order straight away (no re-encoding);
 *  - conversion is idempotent — one order per quotation, enforced by a row
 *    lock here and a UNIQUE key on orders.quotation_id;
 *  - payment terms come from the customer's "qualified for terms" flag;
 *  - the first Job Order is created automatically once production may start
 *    (approved terms, or nothing to pay), otherwise when the required
 *    partial payment is confirmed (PaymentController::confirm).
 */
final class QuotationService
{
    public const APPROVABLE = ['DRAFT', 'SENT', 'REVISION_REQUESTED'];
    public const EDITABLE = ['DRAFT', 'SENT', 'REVISION_REQUESTED'];
    public const SENDABLE = ['DRAFT', 'REVISION_REQUESTED'];
    public const CANCELLABLE = ['DRAFT', 'SENT', 'REVISION_REQUESTED'];

    public static function lock(PDO $pdo, string $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM quotations WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $q = $stmt->fetch();
        if (!$q) {
            throw new RuntimeException('Quotation not found.');
        }
        return $q;
    }

    /** Approve on the customer's behalf (reason required) and create the order. @return array{order_id:string, order_number:string, job_order:?array} */
    public static function approve(string $quotationId, array $actor, string $reason): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $q = self::lock($pdo, $quotationId);
            if (!in_array($q['status'], self::APPROVABLE, true)) {
                throw new RuntimeException('This quotation is ' . str_replace('_', ' ', strtolower($q['status'])) . ' and can no longer be approved.');
            }
            if ((float) $q['total'] < 0 || !self::hasLineItems($pdo, $quotationId)) {
                throw new RuntimeException('A quotation needs at least one line item before it can be approved.');
            }
            $pdo->prepare("UPDATE quotations SET status = 'APPROVED', approved_by_id = :uid, approval_reason = :reason, approved_at = NOW() WHERE id = :id")
                ->execute(['uid' => $actor['id'], 'reason' => $reason, 'id' => $quotationId]);
            AuditService::log($actor['id'], 'QUOTATION_APPROVED_ON_BEHALF', 'Quotation', $quotationId, ['reason' => $reason, 'previous_status' => $q['status']]);
            $q['status'] = 'APPROVED';
            $order = self::createOrder($pdo, $q, $actor['id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $jo = null;
        $summary = PaymentService::paymentSummary($order['order_id']);
        if ($summary['hasApprovedTerms'] || $summary['partialMet']) {
            $jo = ProductionService::autoCreateFirst($order['order_id'], $actor['id']);
        }
        return $order + ['job_order' => $jo];
    }

    /** For an APPROVED quotation that somehow has no order yet (e.g. approved before this release). Idempotent. */
    public static function convert(string $quotationId, string $actorId): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $q = self::lock($pdo, $quotationId);
            if ($q['status'] !== 'APPROVED') {
                throw new RuntimeException('Only an approved quotation can be converted to an order.');
            }
            $order = self::createOrder($pdo, $q, $actorId);
            $pdo->commit();
            return $order;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function hasLineItems(PDO $pdo, string $quotationId): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM quotation_line_items WHERE quotation_id = :id LIMIT 1');
        $stmt->execute(['id' => $quotationId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Caller holds the quotation row lock. Returns the existing order if there already is one. */
    private static function createOrder(PDO $pdo, array $q, ?string $actorId): array
    {
        $existing = $pdo->prepare('SELECT id, order_number FROM orders WHERE quotation_id = :id LIMIT 1');
        $existing->execute(['id' => $q['id']]);
        if ($row = $existing->fetch()) {
            return ['order_id' => $row['id'], 'order_number' => $row['order_number'], 'created' => false];
        }

        $cust = $pdo->prepare('SELECT is_qualified_for_terms FROM customers WHERE id = :id');
        $cust->execute(['id' => $q['customer_id']]);
        $qualified = (bool) $cust->fetchColumn();

        $orderId = Id::generate('ord');
        $orderNumber = NumberSequenceService::next('ORD', 'ORD');
        $pdo->prepare(
            'INSERT INTO orders (id, quotation_id, customer_id, order_number, status, subtotal, discount_type, discount_value, discount_amount, discount_label,
                tax_pct, tax_amount, total_amount, payment_term_type, required_partial_pct, terms_approved_by, terms_reason, notes)
             VALUES (:id, :quotation_id, :customer_id, :order_number, \'OPEN\', :subtotal, :discount_type, :discount_value, :discount_amount, :discount_label,
                :tax_pct, :tax_amount, :total_amount, :term_type, 50, :terms_by, :terms_reason, :notes)'
        )->execute([
            'id' => $orderId,
            'quotation_id' => $q['id'],
            'customer_id' => $q['customer_id'],
            'order_number' => $orderNumber,
            'subtotal' => $q['subtotal'],
            'discount_type' => $q['discount_type'],
            'discount_value' => $q['discount_value'],
            'discount_amount' => $q['discount_amount'],
            'discount_label' => $q['discount_label'],
            'tax_pct' => $q['tax_pct'],
            'tax_amount' => $q['tax_amount'],
            'total_amount' => $q['total'],
            'term_type' => $qualified ? 'APPROVED_TERMS' : 'STANDARD_PARTIAL',
            'terms_by' => $qualified ? 'Automatically applied — customer qualifies for approved payment terms' : null,
            'terms_reason' => $qualified ? 'Customer record is flagged as qualified for approved payment terms.' : null,
            'notes' => $q['notes'],
        ]);

        // Copy of the quotation's line items, so the order never changes if
        // the quotation record is touched later.
        $lines = $pdo->prepare('SELECT * FROM quotation_line_items WHERE quotation_id = :id ORDER BY sort_order ASC, id ASC');
        $lines->execute(['id' => $q['id']]);
        $ins = $pdo->prepare(
            'INSERT INTO order_line_items (id, order_id, product_type, service_id, specs, description, qty, unit, unit_price, notes, sort_order)
             VALUES (:id, :order_id, :product_type, :service_id, :specs, :description, :qty, :unit, :unit_price, :notes, :sort_order)'
        );
        foreach ($lines->fetchAll() as $li) {
            $ins->execute([
                'id' => Id::generate('oli'), 'order_id' => $orderId, 'product_type' => $li['product_type'], 'service_id' => $li['service_id'],
                'specs' => $li['specs'], 'description' => $li['description'], 'qty' => $li['qty'], 'unit' => $li['unit'],
                'unit_price' => $li['unit_price'], 'notes' => $li['notes'], 'sort_order' => $li['sort_order'],
            ]);
        }

        AuditService::log($actorId, 'ORDER_CREATED_FROM_QUOTATION', 'Order', $orderId, [
            'quotation_id' => $q['id'], 'quote_number' => $q['quote_number'], 'order_number' => $orderNumber,
            'payment_term_type' => $qualified ? 'APPROVED_TERMS' : 'STANDARD_PARTIAL',
        ]);
        return ['order_id' => $orderId, 'order_number' => $orderNumber, 'created' => true];
    }
}
