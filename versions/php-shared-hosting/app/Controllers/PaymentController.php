<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Database;
use App\Helpers\Id;
use App\Helpers\View;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\UploadService;

final class PaymentController
{
    public function index(array $params): void
    {
        AuthService::requireLogin();
        $pdo = Database::connection();
        $rows = $pdo->query(
            "SELECT p.*, o.order_number, c.name AS customer_name
             FROM payments p JOIN orders o ON o.id = p.order_id JOIN customers c ON c.id = o.customer_id
             ORDER BY p.payment_date DESC LIMIT 100"
        )->fetchAll();
        View::render('payments/index', ['payments' => $rows]);
    }

    public function store(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF']);
        Csrf::requireValid();

        $orderId = $params['id'];
        $amount = (float) ($_POST['amount'] ?? 0);
        if ($amount <= 0) {
            View::flash('error', 'Enter a valid payment amount.');
            View::redirect('/orders/' . $orderId);
        }

        $id = Id::generate('pay');
        $proofPath = null;

        if (!empty($_FILES['proof']['name'])) {
            try {
                $fileId = UploadService::storePrivate($_FILES['proof'], 'payment', $id, $user['id']);
                $proofPath = $fileId;
            } catch (\Throwable $e) {
                View::flash('error', $e->getMessage());
                View::redirect('/orders/' . $orderId);
            }
        }

        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO payments (id, order_id, amount, method, proof_file_path, status, reference_number, recorded_by_id, notes)
             VALUES (:id, :order_id, :amount, :method, :proof_file_path, :status, :reference_number, :recorded_by_id, :notes)'
        )->execute([
            'id' => $id,
            'order_id' => $orderId,
            'amount' => $amount,
            'method' => $_POST['method'] ?? 'CASH',
            'proof_file_path' => $proofPath,
            'status' => 'PENDING',
            'reference_number' => ($_POST['reference_number'] ?? '') ?: null,
            'recorded_by_id' => $user['id'],
            'notes' => ($_POST['notes'] ?? '') ?: null,
        ]);

        AuditService::log($user['id'], 'PAYMENT_RECORDED', 'Payment', $id, ['order_id' => $orderId, 'amount' => $amount]);
        View::flash('success', 'Payment recorded and awaiting confirmation.');
        View::redirect('/orders/' . $orderId);
    }

    public function confirm(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF']);
        Csrf::requireValid();
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT order_id FROM payments WHERE id = :id');
        $stmt->execute(['id' => $params['id']]);
        $payment = $stmt->fetch();
        if (!$payment) {
            http_response_code(404);
            echo 'Payment not found.';
            return;
        }
        $pdo->prepare("UPDATE payments SET status = 'CONFIRMED' WHERE id = :id AND status = 'PENDING'")->execute(['id' => $params['id']]);
        AuditService::log($user['id'], 'PAYMENT_CONFIRMED', 'Payment', $params['id']);
        View::flash('success', 'Payment confirmed.');
        View::redirect('/orders/' . $payment['order_id']);
    }

    public function reject(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF']);
        Csrf::requireValid();
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT order_id FROM payments WHERE id = :id');
        $stmt->execute(['id' => $params['id']]);
        $payment = $stmt->fetch();
        if (!$payment) {
            http_response_code(404);
            echo 'Payment not found.';
            return;
        }
        $pdo->prepare("UPDATE payments SET status = 'REJECTED' WHERE id = :id AND status = 'PENDING'")->execute(['id' => $params['id']]);
        AuditService::log($user['id'], 'PAYMENT_REJECTED', 'Payment', $params['id']);
        View::flash('success', 'Payment rejected.');
        View::redirect('/orders/' . $payment['order_id']);
    }
}
