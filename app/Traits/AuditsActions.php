<?php

namespace App\Traits;

use App\Models\AuditEvent;

/**
 * Provides a single audit-logging method for services that need to
 * record state transitions. Centralises the AuditEvent::create() call
 * so every service writes audit rows in the same shape.
 */
trait AuditsActions
{
    protected function audit(
        ?int $actorId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
        ?string $purpose = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        AuditEvent::create([
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
            'purpose' => $purpose,
            'request_id' => $requestId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'occurred_at' => now(),
        ]);
    }
}
