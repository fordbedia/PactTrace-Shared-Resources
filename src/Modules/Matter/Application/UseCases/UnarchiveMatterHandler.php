<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The inverse of ArchiveMatterHandler — clears `archived_at`. Same "any
 * status" rule applies: unarchiving is not a status transition. See
 * .claude/rules/matter.md, "Matter Archive / Restore".
 */
class UnarchiveMatterHandler
{
    public function handle(Matter $matter, User $actor): Matter
    {
        $previousStatus = (string) $matter->status;

        $matter->forceFill(['archived_at' => null])->save();

        AuditLog::create([
            'provider_id' => $matter->provider_id,
            'user_id' => $actor->id,
            'action' => 'matter.unarchived',
            'auditable_type' => Matter::class,
            'auditable_id' => $matter->id,
            'metadata' => [
                'previous_status' => $previousStatus,
            ],
        ]);

        return $matter;
    }
}
