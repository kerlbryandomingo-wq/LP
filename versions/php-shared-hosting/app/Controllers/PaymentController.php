<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Csv;
use App\Helpers\Database;
use App\Helpers\FormToken;
use App\Helpers\Id;
use App\Helpers\Input;
use App\Helpers\Paginator;
use App\Helpers\View;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\PaymentService;
use App\Services\ProductionService;
use App\Services\UploadService;

final class PaymentController
{
    public const STATUSES = ['PENDING', 'CONFIRMED', 'REJECTED'];
    public const METHODS = ['CASH' => 'Cash', 'BANK_TRANSFER' => 'Bank Transfer', 'GCASH' => 'GCash', 'MAYA' => 'Maya', 'CHEQUE' => 'Cheque', 'OTHER' => 'Other'];

    private function filters(string &$search, string &$status): array
    {
        $search = Input::query('q');
        $status = Input::query('status');
        $conds = [];
        $bind = [];
        if (in_array($status, self::STATUSES, true)) {
            $conds[] = 'p.status = :status';
            $bind['status'] = $status;
        } else {
            $status = '';
        }
        if ($search !== '') {
            $conds[] = '(o.order_number LIKE :q1 OR c.name LIKE :q2 OR p.reference_number LIKE :q3)';
            $bind += ['q1' => Input::like($search), 'q2' => Input::like($search), 'q3' => Input::like($search)];
        }
        return [$conds ? 'WHERE ' . implode(' AND ', $conds) : '', $bind];
    }

    public function index(array $params): void
    {
        AuthService::authorize('payments.view');
        $pdo = Database::connection();
        $search = $status = '';
        [$where, $bind] = $this->filters($search, $status);
        $pager = new Paginator();
        $from = "FROM payments p JOIN orders o ON o.id = p.order_id JOIN customers c ON c.id = o.customer_id {$where}";
        $count = $pdo->prepare("SELECT COUNT(*) {$from}");
        $count->execute($bind);
        $pager->total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare("SELECT p.*, o.order_number, c.name AS customer_name {$from} ORDER BY p.payment_date DESC, p.created_at DESC LIMIT {$pager->perPage} OFFSET {$pager->offset()}");
        $stmt->execute($bind);
        View::render('payments/index', ['payments' => $stmt->fetchAll(), 'search' => $search, 'status' => $status, 'pager' => $pager, 'pageTitle' => 'Payments']);
    }

    public function export(array $params): void
    {
        $user = AuthService::authorize('exports.run');
        $search = $status = '';
        [$where, $bind] = $this->filters($search, $status);
        $stmt = Database::connection()->prepare(
            "SELECT p.payment_date, o.order_number, c.name AS customer_name, p.amount, p.method, p.reference_number, p.status, u.name AS recorded_by
             FROM payments p JOIN orders o ON o.id = p.order_id JOIN customers c ON c.id = o.customer_id LEFT JOIN users u ON u.id = p.recorded_by_id
             {$where} ORDER BY p.payment_date DESC LIMIT 50000"
        );
        $stmt->execute($bind);
        AuditService::log($user['id'], 'PAYMENTS_EXPORTED', 'Payment', '-', ['status' => $status, 'q' => $search]);
        $rows = (function () use ($stmt) {
            while ($r = $stmt->fetch()) {
                yield [substr((string) $r['payment_date'], 0, 10), $r['order_number'], $r['customer_name'], (float) $r['amount'],
                    self::METHODS[$r['method']] ?? $r['method'], $r['reference_number'], $r['status'], $r['recorded_by']];
            }
        })();
        Csv::download('payments-' . date('Ymd-His') . '.csv', ['Payment Date', 'Order #', 'Customer', 'Amount', 'Method', 'Reference #', 'Status', 'Recorded By'], $rows);
    }

    public function store(array $params): void
    {
        $user = AuthService::authorize('payments.record');
        Csrf::requireValid();
        $order = View::findOr404('SELECT * FROM orders WHERE id = :id', ['id' => $params['id']], 'Order');
        $back = '/orders/' . $order['id'];

        $errors = [];
        $amount = Input::money('amount', 'Amount', $errors, 0.01);
        $method = Input::enum('method', 'Payment method', array_keys(self::METHODS), $errors, 'CASH');
        $date = Input::date('payment_date', 'Payment date', $errors, true);
        $reference = Input::strOrNull('reference_number', 100);
        $notes = Input::strOrNull('notes', 1000);
        if ($date !== null && $date > date('Y-m-d')) {
            $errors[] = 'Payment date cannot be in the future.';
        }
        if ($method !== 'CASH' && $reference === null) {
            $errors[] = 'Enter the reference number for non-cash payments.';
        }
        $hasFile = isset($_FILES['proof']) && is_array($_FILES['proof']) && ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($errors) {
            View::flash('error', implode(' ', $errors));
            View::redirect($back);
        }

        $pdo = Database::connection();
        $stored = null;
        $pdo->beginTransaction();
        try {
            if (!FormToken::consume($pdo, $user['id'])) {
                $pdo->rollBack();
                View::flash('info', 'This payment was already submitted — it was not recorded twice.');
                View::redirect($back);
            }
            $lock = $pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $order['id']]);
            $order = $lock->fetch();
            $problem = null;
            if ($order['status'] === 'CANCELLED') {
                $problem = 'Payments cannot be recorded on a cancelled order.';
            } else {
                // Same rule as the original recordPaymentAction: never more than
                // the outstanding balance (total − confirmed), 1-centavo tolerance.
                $s = PaymentService::paymentSummary($order['id']);
                if ($s['balance'] <= 0.009) {
                    $problem = 'This order has no remaining balance — no payment is needed.';
                } elseif ((float) $amount > $s['balance'] + 0.01) {
                    $problem = 'That amount exceeds the remaining balance of ' . View::money($s['balance']) . '.';
                }
            }
            if (!$problem && $reference !== null) {
                $dup = $pdo->prepare("SELECT 1 FROM payments WHERE order_id = :o AND LOWER(reference_number) = LOWER(:r) AND status <> 'REJECTED' LIMIT 1");
                $dup->execute(['o' => $order['id'], 'r' => $reference]);
                if ($dup->fetchColumn()) {
                    $problem = "A payment with reference {$reference} is already recorded on this order.";
                }
            }
            if ($problem) {
                $pdo->rollBack();
                View::flash('error', $problem);
                View::redirect($back);
            }

            $id = Id::generate('pay');
            $fileId = null;
            if ($hasFile) {
                [$fileId, $stored] = UploadService::storePrivate($_FILES['proof'], 'payment', $id, $user['id']);
            }
            $pdo->prepare(
                'INSERT INTO payments (id, order_id, amount, method, proof_file_path, status, reference_number, payment_date, recorded_by_id, notes)
                 VALUES (:id, :order_id, :amount, :method, :proof, \'PENDING\', :ref, :date, :uid, :notes)'
            )->execute([
                'id' => $id, 'order_id' => $order['id'], 'amount' => $amount, 'method' => $method, 'proof' => $fileId,
                'ref' => $reference, 'date' => $date . ' ' . date('H:i:s'), 'uid' => $user['id'], 'notes' => $notes,
            ]);
            AuditService::log($user['id'], 'PAYMENT_RECORDED', 'Payment', $id, ['order_id' => $order['id'], 'amount' => $amount, 'method' => $method, 'reference' => $reference]);
            $pdo->commit();
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($stored) {
                @unlink($stored);
            }
            View::flash('error', $e->getMessage());
            View::redirect($back);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($stored) {
                @unlink($stored);
            }
            throw $e;
        }

        View::flash('success', 'Payment of ' . View::money($amount) . ' recorded and awaiting confirmation.');
        View::redirect($back);
    }

    public function confirm(array $params): void
    {
        $user = AuthService::authorize('payments.verify');
        Csrf::requireValid();
        $payment = View::findOr404('SELECT * FROM payments WHERE id = :id', ['id' => $params['id']], 'Payment');
        $back = '/orders/' . $payment['order_id'];

        $pdo = Database::connection();
        $pdo->beginTransaction();
        // Lock the order first, then the payment, so two confirmations on the
        // same order are serialized and the balance re-check below is exact.
        $orderLock = $pdo->prepare('SELECT id FROM orders WHERE id = :id FOR UPDATE');
        $orderLock->execute(['id' => $payment['order_id']]);
        $orderLock->fetchAll();
        $lock = $pdo->prepare('SELECT * FROM payments WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $payment['id']]);
        $payment = $lock->fetch();
        $s = PaymentService::paymentSummary($payment['order_id']);
        $problem = match (true) {
            $payment['status'] !== 'PENDING' => 'This payment was already ' . strtolower($payment['status']) . '.',
            $s['order']['status'] === 'CANCELLED' => 'The order is cancelled; reject this payment instead.',
            (float) $payment['amount'] > $s['balance'] + 0.01 => 'Cannot confirm: this payment (' . View::money($payment['amount'])
                . ') exceeds the remaining balance (' . View::money($s['balance']) . '). Reject it and record the correct amount.',
            default => null,
        };
        if ($problem) {
            $pdo->rollBack();
            View::flash('error', $problem);
            View::redirect($back);
        }
        $pdo->prepare("UPDATE payments SET status = 'CONFIRMED', reviewed_by_id = :uid, reviewed_at = NOW() WHERE id = :id")
            ->execute(['uid' => $user['id'], 'id' => $payment['id']]);
        AuditService::log($user['id'], 'PAYMENT_CONFIRMED', 'Payment', $payment['id'], ['order_id' => $payment['order_id'], 'amount' => $payment['amount']]);
        $pdo->commit();

        $msg = 'Payment confirmed.';
        $s = PaymentService::paymentSummary($payment['order_id']);
        if ($s['partialMet'] || $s['hasApprovedTerms']) {
            if ($jo = ProductionService::autoCreateFirst($payment['order_id'], $user['id'])) {
                $msg .= " Required partial payment met — job order {$jo['jo_number']} was created.";
            }
        }
        View::flash('success', $msg);
        View::redirect($back);
    }

    public function reject(array $params): void
    {
        $user = AuthService::authorize('payments.verify');
        Csrf::requireValid();
        $payment = View::findOr404('SELECT * FROM payments WHERE id = :id', ['id' => $params['id']], 'Payment');
        $errors = [];
        $reason = Input::required('reason', 'Reason', $errors, 1000);
        if ($errors) {
            View::flash('error', 'Enter a reason for rejecting the payment.');
            View::redirect('/orders/' . $payment['order_id']);
        }
        $stmt = Database::connection()->prepare(
            "UPDATE payments SET status = 'REJECTED', reviewed_by_id = :uid, reviewed_at = NOW(), reject_reason = :reason WHERE id = :id AND status = 'PENDING'"
        );
        $stmt->execute(['uid' => $user['id'], 'reason' => $reason, 'id' => $payment['id']]);
        if ($stmt->rowCount() === 1) {
            AuditService::log($user['id'], 'PAYMENT_REJECTED', 'Payment', $payment['id'], ['order_id' => $payment['order_id'], 'reason' => $reason]);
            View::flash('success', 'Payment rejected.');
        } else {
            View::flash('error', 'This payment was already ' . strtolower($payment['status']) . '.');
        }
        View::redirect('/orders/' . $payment['order_id']);
    }
}
