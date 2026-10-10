<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Direct PHP port of lib/pricing-totals.ts's computeTotals() — same
 * formula, same rounding, same clamping, byte-for-byte equivalent
 * behavior (verified against the original in tests/PricingServiceTest.php).
 * This is the ONE place both Quotation and Order totals are computed —
 * never duplicated per controller, matching the original's own stated
 * reason for extracting it into one shared function.
 */
final class PricingService
{
    public static function round2(float $n): float
    {
        if (!is_finite($n)) {
            return 0.0;
        }
        return round($n, 2);
    }

    private static function nonNegative($n): float
    {
        $num = is_numeric($n) ? (float) $n : 0.0;
        return max(0.0, $num);
    }

    /**
     * @param array{subtotal: float, discountType: string|null, discountValue: float|string|null, taxPct: float|string|null} $input
     * @return array{subtotal:float, discountType:string, discountValue:float, discountAmount:float, discountLabel:?string, taxPct:float, taxAmount:float, total:float}
     */
    public static function computeTotals(array $input): array
    {
        $subtotal = self::round2(self::nonNegative($input['subtotal'] ?? 0));
        $discountType = ($input['discountType'] ?? '') === 'FIXED' ? 'FIXED' : 'PERCENTAGE';
        $discountValue = self::nonNegative($input['discountValue'] ?? 0);

        if ($discountType === 'PERCENTAGE') {
            $discountValue = min(100.0, $discountValue);
            $discountAmount = self::round2($subtotal * $discountValue / 100);
        } else {
            $discountAmount = self::round2(min($discountValue, $subtotal));
        }

        $taxable = max(0.0, self::round2($subtotal - $discountAmount));
        $taxPct = min(100.0, self::nonNegative($input['taxPct'] ?? 0));
        $taxAmount = self::round2($taxable * $taxPct / 100);
        $total = self::round2($taxable + $taxAmount);

        $discountLabel = null;
        if ($discountAmount > 0) {
            $discountLabel = $discountType === 'PERCENTAGE'
                ? 'Discount (' . self::formatPct($discountValue) . '%)'
                : 'Discount';
        }

        return [
            'subtotal' => $subtotal,
            'discountType' => $discountType,
            'discountValue' => self::round2($discountValue),
            'discountAmount' => $discountAmount,
            'discountLabel' => $discountLabel,
            'taxPct' => self::round2($taxPct),
            'taxAmount' => $taxAmount,
            'total' => $total,
        ];
    }

    private static function formatPct(float $n): string
    {
        $s = rtrim(rtrim(sprintf('%.2f', $n), '0'), '.');
        return $s === '' ? '0' : $s;
    }
}
