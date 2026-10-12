<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
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

final class JobOrderController
{
    public const STATUSES = ['ON_HOLD', 'IN_PROGRESS', 'QC', 'REWORK', 'READY', 'RELEASED', 'COMPLETED'];

    public function index(array $params): void
    {
        AuthService::authorize('production.view');
        $pdo = Database::connection();
        $search = Input::query('q');
        $status = Input::query('status');
        $pager = new Paginator();
        $conds = [];
        $bind = [];
        if (in_array($status, self::STATUSES, true)) {
            $conds[] = 'jo.status = :status';
            $bind['status'] = $status;
        } elseif ($status === 'ALL') {
            // everything
        } else {
            $status = '';
            $conds[] = "jo.status NOT IN ('RELEASED','COMPLETED')";
        }
        if ($search !== '') {
            $conds[] = '(jo.jo_number LIKE :q1 OR o.order_number LIKE :q2 OR c.name LIKE :q3 OR jo.description LIKE :q4)';
            $bind += ['q1' => Input::like($search), 'q2' => Input::like($search), 'q3' => Input::like($search), 'q4' => Input::like($search)];
        }
        $where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';
        $from = "FROM job_orders jo JOIN orders o ON o.id = jo.order_id JOIN customers c ON c.id = o.customer_id
                 LEFT JOIN workflow_stages ws ON ws.template_id = jo.workflow_template_id AND ws.stage_order = jo.current_stage_order {$where}";
        $count = $pdo->prepare("SELECT COUNT(*) {$from}");
        $count->execute($bind);
        $pager->total = (int) $count->fetchColumn();
        $stmt = $pdo->prepare(
            "SELECT jo.*, o.order_number, o.status AS order_status, c.name AS customer_name, ws.name AS stage_name {$from}
             ORDER BY FIELD(jo.priority,'HIGH','MEDIUM','LOW'), jo.deadline IS NULL, jo.deadline, jo.created_at ASC
             LIMIT {$pager->perPage} OFFSET {$pager->offset()}"
        );
        $stmt->execute($bind);
        View::render('production/index', ['jobOrders' => $stmt->fetchAll(), 'search' => $search, 'status' => $status, 'pager' => $pager, 'pageTitle' => 'Production']);
    }

    /** "+ Add Job Order" for an order line item that doesn't have one yet. */
    public function store(array $params): void
    {
        $user = AuthService::authorize('production.manage');
        Csrf::requireValid();
        $orderId = $params['id'];
        $lineId = Input::str('line_item_id', 40);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            if (!FormToken::consume($pdo, $user['id'])) {
                $pdo->rollBack();
                View::flash('info', 'This form was already submitted — no second job order was created.');
                View::redirect('/orders/' . $orderId);
            }
            $o = $pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
            $o->execute(['id' => $orderId]);
            $order = $o->fetch();
            if (!$order) {
                $pdo->rollBack();
                View::notFound('Order');
            }
            $l = $pdo->prepare('SELECT li.* FROM order_line_items li WHERE li.id = :lid AND li.order_id = :oid');
            $l->execute(['lid' => $lineId, 'oid' => $orderId]);
            $line = $l->fetch();
            $has = $pdo->prepare('SELECT jo_number FROM job_orders WHERE order_line_item_id = :lid');
            $has->execute(['lid' => $lineId]);
            $existing = $line ? $has->fetchColumn() : false;
            $problem = match (true) {
                !$line => 'Choose a line item from this order.',
                (bool) $existing => "That line item already has job order {$existing}.",
                default => null,
            };
            if ($problem) {
                $pdo->rollBack();
                View::flash('error', $problem);
                View::redirect('/orders/' . $orderId);
            }
            $jo = ProductionService::createForLine($pdo, $order, $line, $user['id']);
            $pdo->commit();
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            View::flash('error', $e->getMessage());
            View::redirect('/orders/' . $orderId);
        }
        View::flash('success', "Job order {$jo['jo_number']} created.");
        View::redirect('/job-orders/' . $jo['id']);
    }

    public function show(array $params): void
    {
        $user = AuthService::authorize('production.view');
        $pdo = Database::connection();
        $jobOrder = View::findOr404(
            'SELECT jo.*, o.order_number, o.status AS order_status, c.name AS customer_name
             FROM job_orders jo JOIN orders o ON o.id = jo.order_id JOIN customers c ON c.id = o.customer_id WHERE jo.id = :id',
            ['id' => $params['id']],
            'Job order'
        );
        $q = function (string $sql, array $bind) use ($pdo): array {
            $s = $pdo->prepare($sql);
            $s->execute($bind);
            return $s->fetchAll();
        };
        $summary = PaymentService::paymentSummary($jobOrder['order_id']);
        View::render('production/show', [
            'jobOrder' => $jobOrder,
            'stages' => $q('SELECT * FROM workflow_stages WHERE template_id = :tid ORDER BY stage_order ASC', ['tid' => $jobOrder['workflow_template_id']]),
            'qcResults' => $q(
                'SELECT qc.*, u.name AS checked_by_name FROM qc_results qc LEFT JOIN users u ON u.id = qc.checked_by_id WHERE qc.job_order_id = :id ORDER BY qc.seq ASC',
                ['id' => $jobOrder['id']]
            ),
            'history' => $q(
                'SELECT l.*, u.name AS actor_name FROM job_order_stage_logs l LEFT JOIN users u ON u.id = l.actor_id WHERE l.job_order_id = :id ORDER BY l.seq DESC',
                ['id' => $jobOrder['id']]
            ),
            'failing' => ProductionService::failingQcItems($pdo, $jobOrder['id']),
            // Payment figures only for roles that may see finance.
            'summary' => AuthService::can('payments.view') ? $summary : null,
            'canStart' => $summary['hasApprovedTerms'] || $summary['partialMet'],
            'canRelease' => $summary['fullyPaid'] || ((bool) $summary['order']['release_exception'] && !empty($summary['order']['release_exception_by'])),
            'pageTitle' => $jobOrder['jo_number'],
        ]);
    }

    private function expected(): array
    {
        $errors = [];
        $stage = Input::int('expected_stage', 'Stage', $errors, 0, 1000);
        $status = Input::strOrNull('expected_status', 20);
        return [$stage, in_array($status, self::STATUSES, true) ? $status : null];
    }

    public function advanceStage(array $params): void
    {
        $user = AuthService::authorize('production.advance');
        Csrf::requireValid();
        [$stage, $status] = $this->expected();
        try {
            $r = ProductionService::advanceStage($params['id'], $user['id'], $stage, $status);
            View::flash('success', match ($r['status']) {
                'RELEASED' => 'Job order released to the customer.',
                'READY' => 'Job order is ready for release.',
                'QC' => 'Job order moved to Quality Control.',
                default => 'Job order advanced.',
            });
        } catch (\RuntimeException $e) {
            View::flash('error', $e->getMessage());
        }
        View::redirect('/job-orders/' . $params['id']);
    }

    public function undoStage(array $params): void
    {
        $user = AuthService::authorize('production.advance');
        Csrf::requireValid();
        [$stage, $status] = $this->expected();
        try {
            ProductionService::undoLastStageChange($params['id'], $user['id'], $stage, $status);
            View::flash('success', 'Last stage change undone.');
        } catch (\RuntimeException $e) {
            View::flash('error', $e->getMessage());
        }
        View::redirect('/job-orders/' . $params['id']);
    }

    public function recordQc(array $params): void
    {
        $user = AuthService::authorize('qc.record');
        Csrf::requireValid();
        $jo = View::findOr404('SELECT id, status FROM job_orders WHERE id = :id', ['id' => $params['id']], 'Job order');
        $errors = [];
        $label = Input::required('item_label', 'Checklist item', $errors);
        $outcome = Input::enum('outcome', 'Outcome', ['PASS', 'FAIL'], $errors);
        $notes = Input::strOrNull('notes', 1000);
        if ($jo['status'] !== 'QC') {
            $errors[] = 'QC results can only be recorded while the job order is in Quality Control.';
        }
        if ($errors) {
            View::flash('error', implode(' ', $errors));
            View::redirect('/job-orders/' . $jo['id']);
        }
        $pdo = Database::connection();
        $pdo->beginTransaction();
        if (!FormToken::consume($pdo, $user['id'])) {
            $pdo->rollBack();
            View::flash('info', 'This QC result was already recorded.');
            View::redirect('/job-orders/' . $jo['id']);
        }
        $pdo->prepare(
            'INSERT INTO qc_results (id, job_order_id, item_label, checked, outcome, notes, checked_by_id, checked_at)
             VALUES (:id, :jo, :label, 1, :outcome, :notes, :uid, NOW())'
        )->execute(['id' => Id::generate('qcr'), 'jo' => $jo['id'], 'label' => $label, 'outcome' => $outcome, 'notes' => $notes, 'uid' => $user['id']]);
        AuditService::log($user['id'], 'QC_RESULT_RECORDED', 'JobOrder', $jo['id'], ['item' => $label, 'outcome' => $outcome]);
        $pdo->commit();
        View::flash($outcome === 'PASS' ? 'success' : 'error', $outcome === 'PASS' ? "QC passed: {$label}." : "QC failed: {$label}. Fix the item and record a re-check before moving on.");
        View::redirect('/job-orders/' . $jo['id']);
    }
}
