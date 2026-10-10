<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Database;
use App\Helpers\View;
use App\Services\AuthService;

final class DashboardController
{
    public function index(array $params): void
    {
        $user = AuthService::requireLogin();
        $pdo = Database::connection();

        $kpis = [
            'inquiries' => (int) $pdo->query("SELECT COUNT(*) c FROM inquiries WHERE status = 'NEW'")->fetch()['c'],
            'quotations_pending' => (int) $pdo->query("SELECT COUNT(*) c FROM quotations WHERE status = 'SENT'")->fetch()['c'],
            'open_orders' => (int) $pdo->query("SELECT COUNT(*) c FROM orders WHERE status NOT IN ('COMPLETED','CANCELLED')")->fetch()['c'],
            'pending_payments' => (int) $pdo->query("SELECT COUNT(*) c FROM payments WHERE status = 'PENDING'")->fetch()['c'],
        ];

        $recentOrders = $pdo->query(
            "SELECT o.order_number, o.total_amount, o.status, c.name AS customer_name
             FROM orders o JOIN customers c ON c.id = o.customer_id
             ORDER BY o.created_at DESC LIMIT 8"
        )->fetchAll();

        View::render('dashboard/index', ['user' => $user, 'kpis' => $kpis, 'recentOrders' => $recentOrders]);
    }
}
