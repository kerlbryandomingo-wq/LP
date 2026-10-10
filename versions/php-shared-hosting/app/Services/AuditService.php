<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Database;
use App\Helpers\Id;

/** Every sensitive action writes one row here — login, payment confirm/reject, order cancel, etc. (spec section 15). */
final class AuditService
{
    public static function log(?string $actorId, string $action, string $entityType, string $entityId, array $metadata = []): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (id, actor_id, action, entity_type, entity_id, metadata) VALUES (:id, :actor_id, :action, :entity_type, :entity_id, :metadata)'
        );
        $stmt->execute([
            'id' => Id::generate('aud'),
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => json_encode($metadata),
        ]);
    }
}
