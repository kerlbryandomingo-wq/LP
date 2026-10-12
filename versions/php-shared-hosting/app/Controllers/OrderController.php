<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Csv;
use App\Helpers\Database;
use App\Helpers\Input;
use App\Helpers\Paginator;
use App\Helpers\View;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\PaymentService;
use App\Services\ProductionService;

final class OrderController
{
    public const STATUSES = ['OPEN', 'IN_PRODUCTION', 'FULFILLING', 'COMPLETED', 'CANCELLED'];

    /** @return array{0:string,1:array} WHERE clause + bindings shared by the list and the CSV export */
    private function filters(string &$search, string &$status): array
    {
        $search = Input::query('q');
        $status = Input::query('status');
        $conds = [];
        $bind = [];
        if (in_array($status, self::STATUSES, true)) {
            $conds[] = 'o.status = :status';
            $bind['status'] = $status;
        } else {
            $status = '';
        }
        if ($search !== '') {
            $conds[] = '(o.order_number LIKE :q1 OR c.name LIKE :q2)';
            $bind += ['q1' => Input::like($search), 'q2' => Input::like($search)];
        }
        return [$conds ? 'WHERE ' . implode(' AND ', $conds) : '', $bind];
    }

    private const PAID_JOIN = "LEFT JOIN (SELECT order_id, SUM(amount) AS paid FROM payments WHERE status = 'CONFIRMED' GROUP BY order_id) p ON p.order_id = o.id";

    public function index(array $params): void
    {
        AuthService::authorize('orders.view');
        $pdo = Database::connection();
        $search = $status = '';
        [$where, $bind] = $this->filters($search, $status);
        $pager = new Paginator();
        $from = "FROM orders o JOIN customers c ON c.id = o.customer_id {$where}";
        $count = $pdo->prepare("SELECT COUNT(*) {$from}");
        $count->execute($bind);
        $pager->total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare(
            'SELECT o.*, c.name AS customer_name, COALESCE(p.paid, 0) AS paid FROM orders o JOIN customers c ON c.id = o.customer_id '
            . self::PAID_JOIN . " {$where} ORDER BY o.created_at DESC, o.id DESC LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($bind);
        View::render('orders/index', ['orders' => $stmt->fetchAll(), 'search' => $search, 'status' => $status, 'pager' => $pager, 'pageTitle' => 'Orders']);
    }

    public function export(array $params): void
    {
        $user = AuthService::authorize('exports.run');
        $pdo = Database::connection();
        $search = $status = '';
        [$where, $bind] = $this->filters($search, $status);
        $stmt = $pdo->prepare(
            'SELECT o.order_number, c.display_id, c.name AS customer_name, o.status, o.order_date, o.subtotal, o.discount_amount, o.tax_amount,
                    o.total_amount, COALESCE(p.paid, 0) AS paid, o.payment_term_type
             FROM orders o JOIN customers c ON c.id = o.customer_id ' . self::PAID_JOIN . " {$where} ORDER BY o.created_at DESC LIMIT 50000"
        );
        $stmt->execute($bind);
        AuditService::log($user['id'], 'ORDERS_EXPORTED', 'Order', '-', ['status' => $status, 'q' => $search]);
        $rows = (function () use ($stmt) {
            while ($r = $stmt->fetch()) {
                $total = (float) $r['total_amount'];
                $paid = (float) $r['paid'];
                yield [
                    $r['order_number'], $r['display_id'], $r['customer_name'], $r['status'], substr((string) $r['order_date'], 0, 10),
                    (float) $r['subtotal'], (float) $r['discount_amount'], (float) $r['tax_amount'], $total, $paid,
                    max(0.0, round($total - $paid, 2)), $r['payment_term_type'],
                ];
            }
        })();
        Csv::download('orders-' . date('Ymd-His') . '.csv', [
            'Order #', 'Customer ID', 'Customer', 'Status', 'Order Date', 'Subtotal', 'Discount', 'Tax', 'Total', 'Confirmed Paid', 'Balance', 'Payment Terms',
        ], $rows);
    }

    private function loadOrder(string $id): array
    {
        return View::findOr404(
            'SELECT o.*, c.name AS customer_name, c.contact_number, c.email, c.display_id AS customer_display_id, q.quote_number,
                    cu.name AS cancelled_by_name
             FROM orders o JOIN customers c ON c.id = o.customer_id
             LEFT JOIN quotations q ON q.id = o.quotation_id
             LEFT JOIN users cu ON cu.id = o.cancelled_by_id
             WHERE o.id = :id',
            ['id' => $id],
            'Order'
        );
    }

    public function show(array $params): void
    {
        AuthService::authorize('orders.view');
        $order = $this->loadOrder($params['id']);
        $pdo = Database::connection();
        $all = function (string $sql) use ($pdo, $order): array {
            $s = $pdo->prepare($sql);
            $s->execute(['id' => $order['id']]);
            return $s->fetchAll();
        };

        View::render('orders/show', [
            'order' => $order,
            'lineItems' => $all(
                'SELECT li.*, jo.id AS jo_id, jo.jo_number FROM order_line_items li LEFT JOIN job_orders jo ON jo.order_line_item_id = li.id
                 WHERE li.order_id = :id ORDER BY li.sort_order ASC, li.id ASC'
            ),
            'payments' => $all(
                'SELECT p.*, u.name AS recorded_by_name, r.name AS reviewed_by_name, f.original_name AS proof_name
                 FROM payments p LEFT JOIN users u ON u.id = p.recorded_by_id LEFT JOIN users r ON r.id = p.reviewed_by_id
                 LEFT JOIN uploaded_files f ON f.id = p.proof_file_path
                 WHERE p.order_id = :id ORDER BY p.payment_date DESC, p.created_at DESC'
            ),
            'jobOrders' => $all('SELECT * FROM job_orders WHERE order_id = :id ORDER BY created_at ASC'),
            'summary' => PaymentService::paymentSummary($order['id']),
            'pageTitle' => $order['order_number'],
        ]);
    }

    private function lockOrder(\PDO $pdo, string $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $o = $stmt->fetch();
        if (!$o) {
            $pdo->rollBack();
            View::notFound('Order');
        }
        return $o;
    }

    public function cancel(array $params): void
    {
        $user = AuthService::authorize('orders.manage');
        Csrf::requireValid();
        $order = $this->loadOrder($params['id']);
        $errors = [];
        $reason = Input::required('reason', 'Reason', $errors, 1000);
        if ($errors) {
            View::flash('error', 'Enter a reason for cancelling the order.');
            View::redirect('/orders/' . $order['id']);
        }
        $stmt = Database::connection()->prepare(
            "UPDATE orders SET status_before_cancel = status, status = 'CANCELLED', cancelled_at = NOW(), cancelled_by_id = :uid, cancel_reason = :reason
             WHERE id = :id AND status NOT IN ('COMPLETED','CANCELLED')"
        );
        $stmt->execute(['id' => $order['id'], 'uid' => $user['id'], 'reason' => $reason]);
        if ($stmt->rowCount() === 1) {
            AuditService::log($user['id'], 'ORDER_CANCELLED', 'Order', $order['id'], ['reason' => $reason, 'previous_status' => $order['status']]);
            View::flash('success', 'Order cancelled. Its job orders are frozen; confirmed payments remain on record.');
        } else {
            View::flash('error', 'This order is already ' . strtolower($order['status']) . '.');
        }
        View::redirect('/orders/' . $order['id']);
    }

    /** FULFILLING (every job order released) → COMPLETED; job orders become COMPLETED too. */
    public function complete(array $params): void
    {
        $user = AuthService::authorize('orders.manage');
        Csrf::requireValid();
        $pdo = Database::connection();
        $pdo->beginTransaction();
        $order = $this->lockOrder($pdo, $params['id']);
        $open = $pdo->prepare("SELECT COUNT(*) FROM job_orders WHERE order_id = :id AND status NOT IN ('RELEASED','COMPLETED')");
        $open->execute(['id' => $order['id']]);
        $s = PaymentService::paymentSummary($order['id']);
        $exception = (bool) $order['release_exception'] && !empty($order['release_exception_by']);
        $problem = match (true) {
            $order['status'] !== 'FULFILLING' => 'Only an order whose job orders have all been released can be completed.',
            (int) $open->fetchColumn() > 0 => 'Release every job order before completing the order.',
            !$s['fullyPaid'] && !$exception => 'The order still has an unpaid balance of ' . View::money($s['balance']) . '.',
            default => null,
        };
        if ($problem) {
            $pdo->rollBack();
            View::flash('error', $problem);
            View::redirect('/orders/' . $order['id']);
        }
        $pdo->prepare("UPDATE orders SET status = 'COMPLETED', completed_at = NOW() WHERE id = :id")->execute(['id' => $order['id']]);
        $pdo->prepare("UPDATE job_orders SET status = 'COMPLETED', completed_at = NOW() WHERE order_id = :id AND status = 'RELEASED'")->execute(['id' => $order['id']]);
        AuditService::log($user['id'], 'ORDER_COMPLETED', 'Order', $order['id']);
        $pdo->commit();
        View::flash('success', 'Order completed.');
        View::redirect('/orders/' . $order['id']);
    }

    /** Admin-only: allow release without full payment (original "authorized release exception"). */
    public function releaseException(array $params): void
    {
        $user = AuthService::authorize('orders.release_exception');
        Csrf::requireValid();
        $order = $this->loadOrder($params['id']);
        $errors = [];
        $reason = Input::required('reason', 'Reason', $errors, 1000);
        if ($errors || mb_strlen($reason) < 3) {
            View::flash('error', 'Enter a reason for the release exception.');
            View::redirect('/orders/' . $order['id']);
        }
        $stmt = Database::connection()->prepare(
            "UPDATE orders SET release_exception = 1, release_exception_by = :by, release_exception_reason = :reason
             WHERE id = :id AND status NOT IN ('COMPLETED','CANCELLED') AND release_exception = 0"
        );
        $stmt->execute(['id' => $order['id'], 'by' => $user['name'], 'reason' => $reason]);
        if ($stmt->rowCount() === 1) {
            AuditService::log($user['id'], 'ORDER_RELEASE_EXCEPTION_GRANTED', 'Order', $order['id'], ['reason' => $reason]);
            View::flash('success', 'Release exception recorded. Job orders can now be released before full payment.');
        } else {
            View::flash('error', 'A release exception cannot be added to this order.');
        }
        View::redirect('/orders/' . $order['id']);
    }

    /** Admin-only: switch payment terms (standard partial % or approved terms). */
    public function paymentTerms(array $params): void
    {
        $user = AuthService::authorize('orders.approve_terms');
        Csrf::requireValid();
        $order = $this->loadOrder($params['id']);
        $errors = [];
        $type = Input::enum('payment_term_type', 'Payment terms', ['STANDARD_PARTIAL', 'APPROVED_TERMS'], $errors, 'STANDARD_PARTIAL');
        $pct = Input::int('required_partial_pct', 'Required partial %', $errors, 0, 100, 50);
        $approvedBy = $reason = null;
        if ($type === 'APPROVED_TERMS') {
            $approvedBy = Input::required('terms_approved_by', 'Approved by', $errors);
            $reason = Input::required('terms_reason', 'Reason', $errors, 1000);
        }
        $dueDate = Input::date('due_date', 'Due date', $errors);
        if (in_array($order['status'], ['COMPLETED', 'CANCELLED'], true)) {
            $errors[] = 'Payment terms cannot be changed on a ' . strtolower($order['status']) . ' order.';
        }
        if ($errors) {
            View::flash('error', implode(' ', $errors));
            View::redirect('/orders/' . $order['id']);
        }
        Database::connection()->prepare(
            'UPDATE orders SET payment_term_type = :t, required_partial_pct = :p, terms_approved_by = :by, terms_reason = :r, due_date = :d WHERE id = :id'
        )->execute(['t' => $type, 'p' => $pct, 'by' => $approvedBy, 'r' => $reason, 'd' => $dueDate, 'id' => $order['id']]);
        AuditService::log($user['id'], 'ORDER_PAYMENT_TERMS_CHANGED', 'Order', $order['id'], [
            'from' => $order['payment_term_type'], 'to' => $type, 'required_partial_pct' => $pct, 'reason' => $reason,
        ]);
        $jo = null;
        $s = PaymentService::paymentSummary($order['id']);
        if ($s['hasApprovedTerms'] || $s['partialMet']) {
            $jo = ProductionService::autoCreateFirst($order['id'], $user['id']);
        }
        View::flash('success', 'Payment terms updated.' . ($jo ? " Job order {$jo['jo_number']} was created." : ''));
        View::redirect('/orders/' . $order['id']);
    }
}
