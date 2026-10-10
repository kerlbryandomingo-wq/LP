<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Database;
use App\Helpers\View;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\PaymentService;

final class OrderController
{
    public function index(array $params): void
    {
        AuthService::requireLogin();
        $pdo = Database::connection();
        $rows = $pdo->query(
            'SELECT o.*, c.name AS customer_name FROM orders o JOIN customers c ON c.id = o.customer_id ORDER BY o.created_at DESC LIMIT 100'
        )->fetchAll();
        View::render('orders/index', ['orders' => $rows]);
    }

    public function show(array $params): void
    {
        AuthService::requireLogin();
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT o.*, c.name AS customer_name, c.contact_number, c.email FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.id = :id');
        $stmt->execute(['id' => $params['id']]);
        $order = $stmt->fetch();
        if (!$order) {
            http_response_code(404);
            echo 'Order not found.';
            return;
        }

        $stmt2 = $pdo->prepare('SELECT * FROM order_line_items WHERE order_id = :id');
        $stmt2->execute(['id' => $params['id']]);
        $lineItems = $stmt2->fetchAll();

        $stmt3 = $pdo->prepare('SELECT * FROM payments WHERE order_id = :id ORDER BY payment_date DESC');
        $stmt3->execute(['id' => $params['id']]);
        $payments = $stmt3->fetchAll();

        $stmt4 = $pdo->prepare('SELECT * FROM job_orders WHERE order_id = :id');
        $stmt4->execute(['id' => $params['id']]);
        $jobOrders = $stmt4->fetchAll();

        $summary = PaymentService::paymentSummary($params['id']);

        View::render('orders/show', [
            'order' => $order,
            'lineItems' => $lineItems,
            'payments' => $payments,
            'jobOrders' => $jobOrders,
            'summary' => $summary,
        ]);
    }

    public function cancel(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF']);
        Csrf::requireValid();
        $pdo = Database::connection();
        $pdo->prepare(
            "UPDATE orders SET status_before_cancel = status, status = 'CANCELLED', cancelled_at = NOW(), cancelled_by_id = :uid, cancel_reason = :reason WHERE id = :id AND status NOT IN ('COMPLETED','CANCELLED')"
        )->execute(['id' => $params['id'], 'uid' => $user['id'], 'reason' => $_POST['reason'] ?? null]);
        AuditService::log($user['id'], 'ORDER_CANCELLED', 'Order', $params['id']);
        View::flash('success', 'Order cancelled.');
        View::redirect('/orders/' . $params['id']);
    }
}
