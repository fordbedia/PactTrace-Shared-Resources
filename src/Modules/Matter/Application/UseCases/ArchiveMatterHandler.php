<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Archiving is non-destructive and carries no status restriction — a matter
 * of any status (active/on_hold/completed/cancelled) may be archived. It
 * only sets `archived_at`, which hides it from the default `/dashboard/
 * matters` list and the stat cards; the matter, its milestones, documents and
 * messages remain fully intact and queryable. See .claude/rules/matter.md,
 * "Matter Archive / Restore" — mirrors ArchiveDocumentHandler in the Document
 * module.
 */
class ArchiveMatterHandler
{
    public function handle(Matter $matter, User $actor): Matter
    {
        $previousStatus = (string) $matter->status;

        $matter->forceFill(['archived_at' => now()])->save();

        AuditLog::create([
            'provider_id' => $matter->provider_id,
            'user_id' => $actor->id,
            'action' => 'matter.archived',
            'auditable_type' => Matter::class,
            'auditable_id' => $matter->id,
            'metadata' => [
                'previous_status' => $previousStatus,
            ],
        ]);

        return $matter;
    }
}
