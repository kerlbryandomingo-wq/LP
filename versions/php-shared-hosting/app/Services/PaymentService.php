<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use RuntimeException;

/**
 * Direct PHP port of lib/workflow.ts's confirmedPaymentTotal() /
 * paymentSummary() / assertCanStartProduction() / assertCanRelease() —
 * the ONE authoritative place an order's balance and production/release
 * eligibility are computed. Every controller that needs a balance or a
 * payment-gate check calls this, never reimplements the arithmetic.
 */
final class PaymentService
{
    public static function confirmedPaymentTotal(string $orderId): float
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE order_id = :id AND status = 'CONFIRMED'");
        $stmt->execute(['id' => $orderId]);
        return (float) $stmt->fetch()['total'];
    }

    /** @return array{order:array, total:float, confirmed:float, requiredPartial:float, partialMet:bool, fullyPaid:bool, hasApprovedTerms:bool} */
    public static function paymentSummary(string $orderId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new RuntimeException("Order {$orderId} not found.");
        }

        $confirmed = self::confirmedPaymentTotal($orderId);
        $total = (float) $order['total_amount'];
        $requiredPartial = $total * ((int) $order['required_partial_pct'] / 100);
        $hasApprovedTerms = $order['payment_term_type'] === 'APPROVED_TERMS' && !empty($order['terms_approved_by']);

        return [
            'order' => $order,
            'total' => $total,
            'confirmed' => $confirmed,
            'requiredPartial' => $requiredPartial,
            'partialMet' => $confirmed >= $requiredPartial,
            'fullyPaid' => $confirmed >= $total,
            'hasApprovedTerms' => $hasApprovedTerms,
        ];
    }

    /** Rule #1 (preserved verbatim): a Job Order cannot leave ON_HOLD without partial payment or an approved-terms exception. */
    public static function assertCanStartProduction(string $orderId): array
    {
        $s = self::paymentSummary($orderId);
        if ($s['hasApprovedTerms']) {
            return $s;
        }
        if (!$s['partialMet']) {
            throw new RuntimeException(sprintf(
                'On hold: requires %.2f confirmed partial payment (has %.2f), or an approved payment-terms exception.',
                $s['requiredPartial'],
                $s['confirmed']
            ));
        }
        return $s;
    }

    /** Rule #2 (preserved verbatim): a Job Order cannot be RELEASED without full payment or an authorized release exception. */
    public static function assertCanRelease(string $orderId): array
    {
        $s = self::paymentSummary($orderId);
        $order = $s['order'];
        if ((bool) $order['release_exception'] && !empty($order['release_exception_by'])) {
            return $s;
        }
        if (!$s['fullyPaid']) {
            throw new RuntimeException(sprintf(
                'Cannot release: full payment required (%.2f of %.2f confirmed), or an authorized release exception.',
                $s['confirmed'],
                $s['total']
            ));
        }
        return $s;
    }
}
