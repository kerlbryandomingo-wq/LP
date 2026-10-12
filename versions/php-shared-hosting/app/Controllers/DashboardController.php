<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Database;
use App\Helpers\View;
use App\Services\AuthService;
use App\Services\Policy;

final class DashboardController
{
    public function index(array $params): void
    {
        $user = AuthService::authorize('dashboard.view');
        // Production accounts have no access to sales/finance figures; their
        // home is the production list.
        if (!Policy::allows($user, 'orders.view')) {
            View::redirect('/job-orders');
        }
        $pdo = Database::connection();
        $one = fn (string $sql) => $pdo->query($sql)->fetchColumn();

        $kpis = [
            'new_inquiries' => (int) $one("SELECT COUNT(*) FROM inquiries WHERE status = 'NEW'"),
            'quotations_sent' => (int) $one("SELECT COUNT(*) FROM quotations WHERE status = 'SENT'"),
            'open_orders' => (int) $one("SELECT COUNT(*) FROM orders WHERE status NOT IN ('COMPLETED','CANCELLED')"),
            'pending_payments' => (int) $one("SELECT COUNT(*) FROM payments WHERE status = 'PENDING'"),
            'confirmed_this_month' => (float) $one(
                "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'CONFIRMED' AND payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
            ),
            // Same rule as PaymentService: balance = order total − CONFIRMED payments, never below 0; cancelled orders excluded.
            'outstanding' => (float) $one(
                "SELECT COALESCE(SUM(GREATEST(o.total_amount - COALESCE(p.paid,0), 0)),0)
                 FROM orders o
                 LEFT JOIN (SELECT order_id, SUM(amount) paid FROM payments WHERE status='CONFIRMED' GROUP BY order_id) p ON p.order_id = o.id
                 WHERE o.status <> 'CANCELLED'"
            ),
        ];

        $recentOrders = $pdo->query(
            'SELECT o.id, o.order_number, o.total_amount, o.status, c.name AS customer_name
             FROM orders o JOIN customers c ON c.id = o.customer_id
             ORDER BY o.created_at DESC LIMIT 8'
        )->fetchAll();

        View::render('dashboard/index', ['user' => $user, 'kpis' => $kpis, 'recentOrders' => $recentOrders, 'pageTitle' => 'Dashboard']);
    }
}
