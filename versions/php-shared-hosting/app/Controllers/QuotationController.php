<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Database;
use App\Helpers\Id;
use App\Helpers\View;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\NumberSequenceService;
use App\Services\PricingService;

final class QuotationController
{
    public function index(array $params): void
    {
        AuthService::requireLogin();
        $pdo = Database::connection();
        $rows = $pdo->query(
            'SELECT q.*, c.name AS customer_name FROM quotations q JOIN customers c ON c.id = q.customer_id ORDER BY q.created_at DESC LIMIT 100'
        )->fetchAll();
        View::render('quotations/index', ['quotations' => $rows]);
    }

    public function create(array $params): void
    {
        AuthService::requireRole(['ADMIN', 'STAFF']);
        $pdo = Database::connection();
        $customers = $pdo->query('SELECT id, name, display_id FROM customers ORDER BY name LIMIT 500')->fetchAll();
        $services = $pdo->query('SELECT id, name FROM services WHERE active = 1 ORDER BY name')->fetchAll();

        $prefill = null;
        if (!empty($_GET['inquiry_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM inquiries WHERE id = :id');
            $stmt->execute(['id' => $_GET['inquiry_id']]);
            $prefill = $stmt->fetch() ?: null;
        }

        View::render('quotations/form', ['customers' => $customers, 'services' => $services, 'prefill' => $prefill]);
    }

    /** @return array<int, array{description:string, service_id:?string, qty:int, unit:?string, unit_price:float}> */
    private function parseLineItems(): array
    {
        $descriptions = $_POST['description'] ?? [];
        $serviceIds = $_POST['service_id'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        $units = $_POST['unit'] ?? [];
        $prices = $_POST['unit_price'] ?? [];

        $items = [];
        foreach ($descriptions as $i => $desc) {
            $desc = trim((string) $desc);
            if ($desc === '') {
                continue;
            }
            $items[] = [
                'description' => $desc,
                'service_id' => ($serviceIds[$i] ?? '') ?: null,
                'qty' => max(1, (int) ($qtys[$i] ?? 1)),
                'unit' => ($units[$i] ?? '') ?: null,
                'unit_price' => max(0.0, (float) ($prices[$i] ?? 0)),
            ];
        }
        return $items;
    }

    public function store(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF']);
        Csrf::requireValid();

        $customerId = (string) ($_POST['customer_id'] ?? '');
        $items = $this->parseLineItems();

        if ($customerId === '' || !$items) {
            View::flash('error', 'A customer and at least one line item are required.');
            View::redirect('/quotations/new');
        }

        // Server computes the subtotal itself from the submitted line
        // items — never trusts a client-submitted subtotal (spec section
        // 11/15: "do not trust client-side calculations").
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += $item['qty'] * $item['unit_price'];
        }

        $totals = PricingService::computeTotals([
            'subtotal' => $subtotal,
            'discountType' => $_POST['discount_type'] ?? 'PERCENTAGE',
            'discountValue' => $_POST['discount_value'] ?? 0,
            'taxPct' => $_POST['tax_pct'] ?? 0,
        ]);

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $id = Id::generate('quo');
            $quoteNumber = NumberSequenceService::next('QUO', 'QUO');

            $pdo->prepare(
                'INSERT INTO quotations (id, inquiry_id, customer_id, quote_number, status, valid_until, subtotal, discount_type, discount_value, discount_amount, discount_label, tax_pct, tax_amount, total, notes, created_by_id)
                 VALUES (:id, :inquiry_id, :customer_id, :quote_number, :status, :valid_until, :subtotal, :discount_type, :discount_value, :discount_amount, :discount_label, :tax_pct, :tax_amount, :total, :notes, :created_by_id)'
            )->execute([
                'id' => $id,
                'inquiry_id' => ($_POST['inquiry_id'] ?? '') ?: null,
                'customer_id' => $customerId,
                'quote_number' => $quoteNumber,
                'status' => 'DRAFT',
                'valid_until' => ($_POST['valid_until'] ?? '') ?: null,
                'subtotal' => $totals['subtotal'],
                'discount_type' => $totals['discountType'],
                'discount_value' => $totals['discountValue'],
                'discount_amount' => $totals['discountAmount'],
                'discount_label' => $totals['discountLabel'],
                'tax_pct' => $totals['taxPct'],
                'tax_amount' => $totals['taxAmount'],
                'total' => $totals['total'],
                'notes' => ($_POST['notes'] ?? '') ?: null,
                'created_by_id' => $user['id'],
            ]);

            $lineStmt = $pdo->prepare(
                'INSERT INTO quotation_line_items (id, quotation_id, product_type, service_id, description, qty, unit, unit_price)
                 VALUES (:id, :quotation_id, :product_type, :service_id, :description, :qty, :unit, :unit_price)'
            );
            foreach ($items as $item) {
                $lineStmt->execute([
                    'id' => Id::generate('qli'),
                    'quotation_id' => $id,
                    'product_type' => $item['description'],
                    'service_id' => $item['service_id'],
                    'description' => $item['description'],
                    'qty' => $item['qty'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditService::log($user['id'], 'QUOTATION_CREATED', 'Quotation', $id, ['quote_number' => $quoteNumber]);
        View::flash('success', "Quotation {$quoteNumber} created.");
        View::redirect('/quotations/' . $id);
    }

    private function loadQuotation(string $id): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT q.*, c.name AS customer_name, c.id AS cid FROM quotations q JOIN customers c ON c.id = q.customer_id WHERE q.id = :id');
        $stmt->execute(['id' => $id]);
        $quotation = $stmt->fetch();
        if (!$quotation) {
            return null;
        }
        $stmt2 = $pdo->prepare('SELECT * FROM quotation_line_items WHERE quotation_id = :id');
        $stmt2->execute(['id' => $id]);
        $quotation['line_items'] = $stmt2->fetchAll();
        return $quotation;
    }

    public function show(array $params): void
    {
        AuthService::requireLogin();
        $quotation = $this->loadQuotation($params['id']);
        if (!$quotation) {
            http_response_code(404);
            echo 'Quotation not found.';
            return;
        }
        View::render('quotations/show', ['quotation' => $quotation]);
    }

    public function send(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF']);
        Csrf::requireValid();
        $pdo = Database::connection();
        $pdo->prepare("UPDATE quotations SET status = 'SENT' WHERE id = :id AND status = 'DRAFT'")->execute(['id' => $params['id']]);
        AuditService::log($user['id'], 'QUOTATION_SENT', 'Quotation', $params['id']);
        View::flash('success', 'Quotation sent for customer approval.');
        View::redirect('/quotations/' . $params['id']);
    }

    public function approve(array $params): void
    {
        $user = AuthService::requireLogin();
        Csrf::requireValid();
        $pdo = Database::connection();
        $pdo->prepare("UPDATE quotations SET status = 'APPROVED' WHERE id = :id AND status IN ('SENT','DRAFT')")->execute(['id' => $params['id']]);
        AuditService::log($user['id'], 'QUOTATION_APPROVED', 'Quotation', $params['id']);
        View::flash('success', 'Quotation approved.');
        View::redirect('/quotations/' . $params['id']);
    }

    public function reject(array $params): void
    {
        $user = AuthService::requireLogin();
        Csrf::requireValid();
        $pdo = Database::connection();
        $pdo->prepare("UPDATE quotations SET status = 'REJECTED', reject_reason = :reason WHERE id = :id")
            ->execute(['id' => $params['id'], 'reason' => $_POST['reason'] ?? null]);
        AuditService::log($user['id'], 'QUOTATION_REJECTED', 'Quotation', $params['id']);
        View::flash('success', 'Quotation rejected.');
        View::redirect('/quotations/' . $params['id']);
    }

    /** Quotation -> Order conversion, copying line items + pricing breakdown exactly (preserves the original's "Order created from a Quotation copies the breakdown straight from it" rule). */
    public function convertToOrder(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF']);
        Csrf::requireValid();

        $quotation = $this->loadQuotation($params['id']);
        if (!$quotation || $quotation['status'] !== 'APPROVED') {
            View::flash('error', 'Only an approved quotation can be converted to an order.');
            View::redirect('/quotations/' . $params['id']);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $orderId = Id::generate('ord');
            $orderNumber = NumberSequenceService::next('ORD', 'ORD');

            $pdo->prepare(
                'INSERT INTO orders (id, quotation_id, customer_id, order_number, status, subtotal, discount_type, discount_value, discount_amount, discount_label, tax_pct, tax_amount, total_amount, payment_term_type, required_partial_pct)
                 VALUES (:id, :quotation_id, :customer_id, :order_number, :status, :subtotal, :discount_type, :discount_value, :discount_amount, :discount_label, :tax_pct, :tax_amount, :total_amount, :payment_term_type, :required_partial_pct)'
            )->execute([
                'id' => $orderId,
                'quotation_id' => $quotation['id'],
                'customer_id' => $quotation['cid'],
                'order_number' => $orderNumber,
                'status' => 'OPEN',
                'subtotal' => $quotation['subtotal'],
                'discount_type' => $quotation['discount_type'],
                'discount_value' => $quotation['discount_value'],
                'discount_amount' => $quotation['discount_amount'],
                'discount_label' => $quotation['discount_label'],
                'tax_pct' => $quotation['tax_pct'],
                'tax_amount' => $quotation['tax_amount'],
                'total_amount' => $quotation['total'],
                'payment_term_type' => $_POST['payment_term_type'] ?? 'STANDARD_PARTIAL',
                'required_partial_pct' => (int) ($_POST['required_partial_pct'] ?? 50),
            ]);

            // Order reads its items from the Quotation's line items in the
            // original app; this port persists a copy onto order_line_items
            // too so Order detail never depends on a join back to a
            // Quotation that might later be edited — same end result,
            // simpler/safer read path for a PHP port.
            $lineStmt = $pdo->prepare(
                'INSERT INTO order_line_items (id, order_id, product_type, service_id, description, qty, unit, unit_price)
                 VALUES (:id, :order_id, :product_type, :service_id, :description, :qty, :unit, :unit_price)'
            );
            foreach ($quotation['line_items'] as $li) {
                $lineStmt->execute([
                    'id' => Id::generate('oli'),
                    'order_id' => $orderId,
                    'product_type' => $li['product_type'],
                    'service_id' => $li['service_id'],
                    'description' => $li['description'],
                    'qty' => $li['qty'],
                    'unit' => $li['unit'],
                    'unit_price' => $li['unit_price'],
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditService::log($user['id'], 'ORDER_CREATED_FROM_QUOTATION', 'Order', $orderId, ['quotation_id' => $quotation['id']]);
        View::flash('success', "Order {$orderNumber} created from quotation.");
        View::redirect('/orders/' . $orderId);
    }
}
