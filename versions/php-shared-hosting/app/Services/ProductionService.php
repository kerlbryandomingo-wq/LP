<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Id;
use RuntimeException;

/**
 * Button-based Job Order stage progression + Undo (spec section 10:
 * "preserve button-based progression ... do not remove it merely
 * because drag-and-drop has been implemented" — this port implements
 * the button-based path only; drag-and-drop is documented as deferred
 * in FEATURE-PARITY.md, never silently dropped).
 *
 * Every advance is written to job_order_stage_logs before the job_order
 * row changes, in the same transaction — Undo restores the exact prior
 * (stage_order, status) from that log rather than just decrementing,
 * matching the original's revertStageChange().
 */
final class ProductionService
{
    public static function advanceStage(string $jobOrderId, string $actorId): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM job_orders WHERE id = :id');
        $stmt->execute(['id' => $jobOrderId]);
        $jo = $stmt->fetch();
        if (!$jo) {
            throw new RuntimeException('Job order not found.');
        }

        // Rule #1 (PaymentService::assertCanStartProduction) — a Job
        // Order leaving ON_HOLD for the first time requires partial
        // payment or an approved-terms exception.
        if ($jo['status'] === 'ON_HOLD') {
            PaymentService::assertCanStartProduction($jo['order_id']);
        }

        $stagesStmt = $pdo->prepare('SELECT * FROM workflow_stages WHERE template_id = :tid ORDER BY stage_order ASC');
        $stagesStmt->execute(['tid' => $jo['workflow_template_id']]);
        $stages = $stagesStmt->fetchAll();
        if (!$stages) {
            throw new RuntimeException('This job order\'s workflow template has no stages configured.');
        }

        $currentIndex = null;
        foreach ($stages as $i => $stage) {
            if ((int) $stage['stage_order'] === (int) $jo['current_stage_order']) {
                $currentIndex = $i;
                break;
            }
        }
        $currentIndex ??= -1;

        $fromStageOrder = (int) $jo['current_stage_order'];
        $fromStatus = $jo['status'];

        if ($fromStatus === 'READY') {
            // All stages are already done — "advance" from here means
            // releasing the finished job order to the customer, gated by
            // full payment or an authorized release exception (Rule #2).
            PaymentService::assertCanRelease($jo['order_id']);
            $toStageOrder = $fromStageOrder;
            $toStatus = 'RELEASED';
        } else {
            $nextIndex = $currentIndex + 1;
            if ($nextIndex >= count($stages)) {
                throw new RuntimeException('This job order has no further stage to advance to.');
            }
            $nextStage = $stages[$nextIndex];
            $toStageOrder = (int) $nextStage['stage_order'];
            $isLastStage = $nextIndex === count($stages) - 1;

            if ((bool) $nextStage['is_qc_stage']) {
                $toStatus = 'QC';
            } elseif ($isLastStage) {
                $toStatus = 'READY';
            } else {
                $toStatus = 'IN_PROGRESS';
            }
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO job_order_stage_logs (id, job_order_id, from_stage_order, to_stage_order, from_status, to_status, actor_id)
                 VALUES (:id, :jo, :from_order, :to_order, :from_status, :to_status, :actor)'
            )->execute([
                'id' => Id::generate('jol'),
                'jo' => $jobOrderId,
                'from_order' => $fromStageOrder,
                'to_order' => $toStageOrder,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'actor' => $actorId,
            ]);

            $pdo->prepare(
                "UPDATE job_orders SET current_stage_order = :order, status = :status WHERE id = :id"
            )->execute(['order' => $toStageOrder, 'status' => $toStatus, 'id' => $jobOrderId]);

            if ($toStatus === 'RELEASED') {
                $pdo->prepare("UPDATE orders SET status = 'FULFILLING' WHERE id = :id AND status != 'CANCELLED'")->execute(['id' => $jo['order_id']]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditService::log($actorId, 'JOB_ORDER_ADVANCED', 'JobOrder', $jobOrderId, ['to_status' => $toStatus]);
        return ['status' => $toStatus, 'stage_order' => $toStageOrder];
    }

    /** Restores the job order to the (stage_order, status) recorded just before its most recent advance. */
    public static function undoLastStageChange(string $jobOrderId, string $actorId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM job_order_stage_logs WHERE job_order_id = :id ORDER BY created_at DESC LIMIT 1');
        $stmt->execute(['id' => $jobOrderId]);
        $log = $stmt->fetch();
        if (!$log) {
            throw new RuntimeException('There is no recorded stage change to undo.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE job_orders SET current_stage_order = :order, status = :status WHERE id = :id')->execute([
                'order' => $log['from_stage_order'] ?? 1,
                'status' => $log['from_status'] ?? 'ON_HOLD',
                'id' => $jobOrderId,
            ]);
            $pdo->prepare('DELETE FROM job_order_stage_logs WHERE id = :id')->execute(['id' => $log['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        AuditService::log($actorId, 'JOB_ORDER_STAGE_UNDONE', 'JobOrder', $jobOrderId);
    }
}
