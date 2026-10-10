<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csrf;
use App\Helpers\Database;
use App\Helpers\Id;
use App\Helpers\View;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\ProductionService;

final class JobOrderController
{
    /** Creates a Job Order from an Order's line items (the original app's "+ Add Job Order" action on Order detail). */
    public function store(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF']);
        Csrf::requireValid();

        $orderId = $params['id'];
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT * FROM order_line_items WHERE order_id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $line = $stmt->fetch();
        if (!$line) {
            View::flash('error', 'This order has no line items to build a job order from.');
            View::redirect('/orders/' . $orderId);
        }

        $templateStmt = $pdo->query('SELECT id FROM workflow_templates WHERE active = 1 ORDER BY created_at ASC LIMIT 1');
        $template = $templateStmt->fetch();
        if (!$template) {
            View::flash('error', 'No active workflow template is configured.');
            View::redirect('/orders/' . $orderId);
        }

        $id = Id::generate('jo');
        $joNumber = \App\Services\NumberSequenceService::next('JO', 'JO');

        $pdo->prepare(
            'INSERT INTO job_orders (id, order_id, jo_number, product_type, service_id, description, quantity, workflow_template_id, current_stage_order, status)
             VALUES (:id, :order_id, :jo_number, :product_type, :service_id, :description, :quantity, :template_id, 1, :status)'
        )->execute([
            'id' => $id,
            'order_id' => $orderId,
            'jo_number' => $joNumber,
            'product_type' => $line['product_type'],
            'service_id' => $line['service_id'],
            'description' => $line['description'],
            'quantity' => $line['qty'],
            'template_id' => $template['id'],
            'status' => 'ON_HOLD',
        ]);

        $pdo->prepare("UPDATE orders SET status = 'IN_PRODUCTION' WHERE id = :id AND status = 'OPEN'")->execute(['id' => $orderId]);

        AuditService::log($user['id'], 'JOB_ORDER_CREATED', 'JobOrder', $id, ['order_id' => $orderId]);
        View::flash('success', "Job order {$joNumber} created.");
        View::redirect('/job-orders/' . $id);
    }


    public function index(array $params): void
    {
        AuthService::requireLogin();
        $pdo = Database::connection();
        $rows = $pdo->query(
            "SELECT jo.*, o.order_number, c.name AS customer_name
             FROM job_orders jo JOIN orders o ON o.id = jo.order_id JOIN customers c ON c.id = o.customer_id
             WHERE jo.status != 'COMPLETED'
             ORDER BY jo.created_at DESC LIMIT 100"
        )->fetchAll();
        View::render('production/index', ['jobOrders' => $rows]);
    }

    public function show(array $params): void
    {
        AuthService::requireLogin();
        $pdo = Database::connection();

        $stmt = $pdo->prepare(
            "SELECT jo.*, o.order_number, c.name AS customer_name
             FROM job_orders jo JOIN orders o ON o.id = jo.order_id JOIN customers c ON c.id = o.customer_id
             WHERE jo.id = :id"
        );
        $stmt->execute(['id' => $params['id']]);
        $jobOrder = $stmt->fetch();
        if (!$jobOrder) {
            http_response_code(404);
            echo 'Job order not found.';
            return;
        }

        $stagesStmt = $pdo->prepare('SELECT * FROM workflow_stages WHERE template_id = :tid ORDER BY stage_order ASC');
        $stagesStmt->execute(['tid' => $jobOrder['workflow_template_id']]);
        $stages = $stagesStmt->fetchAll();

        $qcStmt = $pdo->prepare('SELECT * FROM qc_results WHERE job_order_id = :id ORDER BY created_at ASC');
        $qcStmt->execute(['id' => $params['id']]);
        $qcResults = $qcStmt->fetchAll();

        View::render('production/show', ['jobOrder' => $jobOrder, 'stages' => $stages, 'qcResults' => $qcResults]);
    }

    public function advanceStage(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF', 'PRODUCTION']);
        Csrf::requireValid();
        try {
            ProductionService::advanceStage($params['id'], $user['id']);
            View::flash('success', 'Job order advanced to the next stage.');
        } catch (\Throwable $e) {
            View::flash('error', $e->getMessage());
        }
        View::redirect('/job-orders/' . $params['id']);
    }

    public function undoStage(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF', 'PRODUCTION']);
        Csrf::requireValid();
        try {
            ProductionService::undoLastStageChange($params['id'], $user['id']);
            View::flash('success', 'Last stage change undone.');
        } catch (\Throwable $e) {
            View::flash('error', $e->getMessage());
        }
        View::redirect('/job-orders/' . $params['id']);
    }

    public function recordQc(array $params): void
    {
        $user = AuthService::requireRole(['ADMIN', 'STAFF', 'PRODUCTION']);
        Csrf::requireValid();

        $pdo = Database::connection();
        $label = trim((string) ($_POST['item_label'] ?? ''));
        $outcome = $_POST['outcome'] ?? null;
        if ($label === '' || !in_array($outcome, ['PASS', 'FAIL'], true)) {
            View::flash('error', 'A checklist item and PASS/FAIL outcome are required.');
            View::redirect('/job-orders/' . $params['id']);
        }

        $pdo->prepare(
            'INSERT INTO qc_results (id, job_order_id, item_label, checked, outcome, notes, checked_by_id, checked_at)
             VALUES (:id, :jo, :label, 1, :outcome, :notes, :uid, NOW())'
        )->execute([
            'id' => Id::generate('qcr'),
            'jo' => $params['id'],
            'label' => $label,
            'outcome' => $outcome,
            'notes' => ($_POST['notes'] ?? '') ?: null,
            'uid' => $user['id'],
        ]);

        AuditService::log($user['id'], 'QC_RESULT_RECORDED', 'JobOrder', $params['id'], ['outcome' => $outcome]);
        View::flash('success', 'QC result recorded.');
        View::redirect('/job-orders/' . $params['id']);
    }
}
