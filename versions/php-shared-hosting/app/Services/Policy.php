<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Single source of truth for "which role may do what". Every controller
 * action calls AuthService::authorize('<ability>'); navigation links are
 * hidden with the same check, but hiding is never the protection.
 *
 * This is a simplified version of the original app's model: the Next.js
 * app gives STAFF accounts individually granted permissions, while this
 * edition gives every STAFF account the same staff abilities (documented
 * in FEATURE-PARITY.md). CUSTOMER accounts can't use this edition at all —
 * the customer portal isn't ported — so they have no abilities and can't
 * sign in.
 */
final class Policy
{
    private const PRODUCTION = [
        'dashboard.view', 'production.view', 'production.advance', 'qc.record', 'inventory.view',
    ];

    private const STAFF = [
        'dashboard.view',
        'customers.view', 'customers.manage',
        'inquiries.view', 'inquiries.manage',
        'quotations.view', 'quotations.manage', 'quotations.approve',
        'orders.view', 'orders.manage',
        'payments.view', 'payments.record', 'payments.verify',
        'production.view', 'production.manage', 'production.advance', 'qc.record',
        'inventory.view', 'inventory.manage',
        'exports.run',
    ];

    // ADMIN has every ability, including the admin-only ones:
    // users.manage, system.updates, orders.release_exception, orders.approve_terms.

    public static function allows(?array $user, string $ability): bool
    {
        if (!$user || empty($user['active'])) {
            return false;
        }
        return match ($user['role']) {
            'ADMIN' => true,
            'STAFF' => in_array($ability, self::STAFF, true),
            'PRODUCTION' => in_array($ability, self::PRODUCTION, true),
            default => false,
        };
    }
}
