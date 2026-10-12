<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Id;
use PDO;
use RuntimeException;

/**
 * Job orders: creation, button-based stage progression, Undo, QC gate.
 *
 * Every state change runs in a transaction that locks the job order row
 * (SELECT … FOR UPDATE) and checks the state the user was looking at
 * (expected stage/status posted by the form). A double-click or a stale
 * browser tab therefore can never advance a job order twice.
 *
 * Every advance is written to job_order_stage_logs in the same
 * transaction; Undo restores the exact prior (stage_order, status) from
 * that log, matching the original's revertStageChange().
 */
final class ProductionService
{
    /** Default workflow used for new job orders (the services catalogue has no per-service template in this edition). */
    public static function defaultTemplateId(PDO $pdo): string
    {
        $id = $pdo->query('SELECT id FROM workflow_templates WHERE active = 1 ORDER BY created_at ASC, id ASC LIMIT 1')->fetchColumn();
        if (!$id) {
            throw new RuntimeException('No active production workflow is configured.');
        }
        return (string) $id;
    }

    /**
     * Creates one job order for one order line item. Caller must hold a
     * transaction with the order row locked FOR UPDATE.
     */
    public static function createForLine(PDO $pdo, array $order, array $line, ?string $actorId): array
    {
        if (in_array($order['status'], ['COMPLETED', 'CANCELLED'], true)) {
            throw new RuntimeException('Job orders cannot be added to a ' . strtolower($order['status']) . ' order.');
        }
        $id = Id::generate('jo');
        $joNumber = NumberSequenceService::next('JO', 'JO');
        $pdo->prepare(
            'INSERT INTO job_orders (id, order_id, order_line_item_id, jo_number, product_type, service_id, specs, description, quantity, workflow_template_id, current_stage_order, status)
             VALUES (:id, :order_id, :line_id, :jo_number, :product_type, :service_id, :specs, :description, :quantity, :template_id, 1, \'ON_HOLD\')'
        )->execute([
            'id' => $id,
            'order_id' => $order['id'],
            'line_id' => $line['id'],
            'jo_number' => $joNumber,
            'product_type' => $line['product_type'],
            'service_id' => $line['service_id'],
            'specs' => $line['specs'],
            'description' => $line['description'],
            'quantity' => $line['qty'],
            'template_id' => self::defaultTemplateId($pdo),
        ]);
        AuditService::log($actorId, 'JOB_ORDER_CREATED', 'JobOrder', $id, ['order_id' => $order['id'], 'jo_number' => $joNumber, 'line_item_id' => $line['id']]);
        return ['id' => $id, 'jo_number' => $joNumber];
    }

    /**
     * Mirrors the original's autoCreateJobOrderForOrder(): once an order is
     * allowed to start (approved terms, or required partial payment
     * confirmed) its first job order is created automatically from the
     * first line item. Idempotent — does nothing if any job order exists.
     */
    public static function autoCreateFirst(string $orderId, ?string $actorId): ?array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $orderId]);
            $order = $stmt->fetch();
            $exists = $pdo->prepare('SELECT 1 FROM job_orders WHERE order_id = :id LIMIT 1');
            $exists->execute(['id' => $orderId]);
            if (!$order || $exists->fetchColumn() || in_array($order['status'], ['COMPLETED', 'CANCELLED'], true)) {
                $pdo->rollBack();
                return null;
            }
            $lineStmt = $pdo->prepare('SELECT * FROM order_line_items WHERE order_id = :id ORDER BY sort_order ASC, id ASC LIMIT 1');
            $lineStmt->execute(['id' => $orderId]);
            $line = $lineStmt->fetch();
            if (!$line) {
                $pdo->rollBack();
                return null;
            }
            $jo = self::createForLine($pdo, $order, $line, $actorId);
            $pdo->commit();
            return $jo;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function lockJobOrder(PDO $pdo, string $jobOrderId): array
    {
        $stmt = $pdo->prepare('SELECT jo.*, o.status AS order_status FROM job_orders jo JOIN orders o ON o.id = jo.order_id WHERE jo.id = :id FOR UPDATE');
        $stmt->execute(['id' => $jobOrderId]);
        $jo = $stmt->fetch();
        if (!$jo) {
            throw new RuntimeException('Job order not found.');
        }
        return $jo;
    }

    private static function assertExpected(array $jo, ?int $expectedStage, ?string $expectedStatus): void
    {
        if ($expectedStage !== null && $expectedStatus !== null
            && ((int) $jo['current_stage_order'] !== $expectedStage || $jo['status'] !== $expectedStatus)) {
            throw new RuntimeException('This job order was already updated (possibly by a double-click or another user). The page now shows its current stage.');
        }
    }

    /** @return string[] item labels whose most recent QC result is FAIL */
    public static function failingQcItems(PDO $pdo, string $jobOrderId): array
    {
        $stmt = $pdo->prepare('SELECT item_label, outcome FROM qc_results WHERE job_order_id = :id ORDER BY seq ASC');
        $stmt->execute(['id' => $jobOrderId]);
        $latest = [];
        foreach ($stmt->fetchAll() as $r) {
            $latest[mb_strtolower(trim($r['item_label']))] = [$r['item_label'], $r['outcome']];
        }
        return array_values(array_map(fn ($x) => $x[0], array_filter($latest, fn ($x) => $x[1] === 'FAIL')));
    }

    public static function qcResultCount(PDO $pdo, string $jobOrderId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM qc_results WHERE job_order_id = :id');
        $stmt->execute(['id' => $jobOrderId]);
        return (int) $stmt->fetchColumn();
    }

    public static function advanceStage(string $jobOrderId, string $actorId, ?int $expectedStage = null, ?string $expectedStatus = null): array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $jo = self::lockJobOrder($pdo, $jobOrderId);
            self::assertExpected($jo, $expectedStage, $expectedStatus);

            if (in_array($jo['order_status'], ['CANCELLED', 'COMPLETED'], true)) {
                throw new RuntimeException('The order is ' . strtolower($jo['order_status']) . '; its job orders can no longer change stage.');
            }
            if (in_array($jo['status'], ['RELEASED', 'COMPLETED'], true)) {
                throw new RuntimeException('This job order has already been released.');
            }
            // Rule #1 (PaymentService::assertCanStartProduction) — leaving
            // ON_HOLD requires the partial payment or approved terms.
            if ($jo['status'] === 'ON_HOLD') {
                PaymentService::assertCanStartProduction($jo['order_id']);
            }

            $stagesStmt = $pdo->prepare('SELECT * FROM workflow_stages WHERE template_id = :tid ORDER BY stage_order ASC');
            $stagesStmt->execute(['tid' => $jo['workflow_template_id']]);
            $stages = $stagesStmt->fetchAll();
            if (!$stages) {
                throw new RuntimeException('This job order\'s workflow has no stages configured.');
            }

            $currentIndex = 0;
            foreach ($stages as $i => $stage) {
                if ((int) $stage['stage_order'] === (int) $jo['current_stage_order']) {
                    $currentIndex = $i;
                    break;
                }
            }
            $fromStageOrder = (int) $jo['current_stage_order'];
            $fromStatus = $jo['status'];

            if ($fromStatus === 'ON_HOLD') {
                // Starting production: work begins on the current (first) stage.
                $toStageOrder = $fromStageOrder;
                $toStatus = (bool) $stages[$currentIndex]['is_qc_stage'] ? 'QC' : 'IN_PROGRESS';
            } elseif ($fromStatus === 'READY') {
                // Rule #2 — release requires full payment or an authorized release exception.
                PaymentService::assertCanRelease($jo['order_id']);
                $toStageOrder = $fromStageOrder;
                $toStatus = 'RELEASED';
            } else {
                if ($fromStatus === 'QC') {
                    if (self::qcResultCount($pdo, $jobOrderId) === 0) {
                        throw new RuntimeException('Record at least one QC checklist result before moving past Quality Control.');
                    }
                    $failing = self::failingQcItems($pdo, $jobOrderId);
                    if ($failing) {
                        throw new RuntimeException('QC failed for: ' . implode(', ', $failing) . '. Record a passing re-check for each item before moving on.');
                    }
                }
                $nextIndex = $currentIndex + 1;
                if ($nextIndex >= count($stages)) {
                    $toStageOrder = $fromStageOrder;
                    $toStatus = 'READY';
                } else {
                    $nextStage = $stages[$nextIndex];
                    $toStageOrder = (int) $nextStage['stage_order'];
                    $isLast = $nextIndex === count($stages) - 1;
                    $toStatus = (bool) $nextStage['is_qc_stage'] ? 'QC' : ($isLast ? 'READY' : 'IN_PROGRESS');
                }
            }

            $pdo->prepare(
                'INSERT INTO job_order_stage_logs (id, job_order_id, from_stage_order, to_stage_order, from_status, to_status, actor_id)
                 VALUES (:id, :jo, :from_order, :to_order, :from_status, :to_status, :actor)'
            )->execute([
                'id' => Id::generate('jol'), 'jo' => $jobOrderId, 'from_order' => $fromStageOrder, 'to_order' => $toStageOrder,
                'from_status' => $fromStatus, 'to_status' => $toStatus, 'actor' => $actorId,
            ]);
            $pdo->prepare('UPDATE job_orders SET current_stage_order = :order, status = :status WHERE id = :id')
                ->execute(['order' => $toStageOrder, 'status' => $toStatus, 'id' => $jobOrderId]);

            self::syncOrderStatus($pdo, $jo['order_id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        AuditService::log($actorId, 'JOB_ORDER_ADVANCED', 'JobOrder', $jobOrderId, ['from' => $fromStatus, 'to' => $toStatus, 'stage' => $toStageOrder]);
        return ['status' => $toStatus, 'stage_order' => $toStageOrder];
    }

    /** Restores the job order to the (stage_order, status) recorded just before its most recent advance. */
    public static function undoLastStageChange(string $jobOrderId, string $actorId, ?int $expectedStage = null, ?string $expectedStatus = null): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $jo = self::lockJobOrder($pdo, $jobOrderId);
            self::assertExpected($jo, $expectedStage, $expectedStatus);
            if (in_array($jo['order_status'], ['CANCELLED', 'COMPLETED'], true)) {
                throw new RuntimeException('The order is ' . strtolower($jo['order_status']) . '; stage changes can no longer be undone.');
            }
            $stmt = $pdo->prepare('SELECT * FROM job_order_stage_logs WHERE job_order_id = :id ORDER BY seq DESC LIMIT 1');
            $stmt->execute(['id' => $jobOrderId]);
            $log = $stmt->fetch();
            if (!$log) {
                throw new RuntimeException('There is no recorded stage change to undo.');
            }
            $pdo->prepare('UPDATE job_orders SET current_stage_order = :order, status = :status WHERE id = :id')->execute([
                'order' => $log['from_stage_order'] ?? 1,
                'status' => $log['from_status'] ?? 'ON_HOLD',
                'id' => $jobOrderId,
            ]);
            $pdo->prepare('DELETE FROM job_order_stage_logs WHERE id = :id')->execute(['id' => $log['id']]);
            self::syncOrderStatus($pdo, $jo['order_id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        AuditService::log($actorId, 'JOB_ORDER_STAGE_UNDONE', 'JobOrder', $jobOrderId, ['restored_status' => $log['from_status'], 'restored_stage' => $log['from_stage_order']]);
    }

    /**
     * Order status follows its job orders (never touches CANCELLED/COMPLETED):
     * all released → FULFILLING; any started → IN_PRODUCTION; otherwise OPEN.
     */
    public static function syncOrderStatus(PDO $pdo, string $orderId): void
    {
        $stmt = $pdo->prepare('SELECT status FROM job_orders WHERE order_id = :id');
        $stmt->execute(['id' => $orderId]);
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$statuses) {
            $new = 'OPEN';
        } elseif (!array_diff($statuses, ['RELEASED', 'COMPLETED'])) {
            $new = 'FULFILLING';
        } elseif (array_diff($statuses, ['ON_HOLD'])) {
            $new = 'IN_PRODUCTION';
        } else {
            $new = 'OPEN';
        }
        $pdo->prepare("UPDATE orders SET status = :s WHERE id = :id AND status NOT IN ('CANCELLED','COMPLETED')")
            ->execute(['s' => $new, 'id' => $orderId]);
    }
}
