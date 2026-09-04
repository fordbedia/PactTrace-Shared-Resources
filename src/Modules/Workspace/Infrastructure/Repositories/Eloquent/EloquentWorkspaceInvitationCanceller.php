<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Infrastructure\Repositories\Eloquent;

use PactTrackSDK\SharedResources\Modules\Client\Models\ClientInvitation;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceInvitationCanceller;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Scopes\WorkspaceScope;

/**
 * Expires a deactivated workspace's still-open client invitations in SQL.
 *
 * "Expire" rather than delete: the row is kept for the audit trail, and a
 * `ClientInvitation` past `expires_at` already reads as "not found" to the
 * accept flow (`EloquentClientInvitationRepository::findValidByToken`
 * filters `expires_at > now()`), so the link stops working with no email and
 * no schema change.
 *
 * The matter lookup drops `WorkspaceScope` and filters `workspace_id`
 * explicitly — same reasoning as `EloquentWorkspaceDeactivationSignals`: the
 * ambient scope is unreliable here and a queue/console caller carries none.
 */
final class EloquentWorkspaceInvitationCanceller implements WorkspaceInvitationCanceller
{
    public function expirePendingClientInvitationsForWorkspace(int $workspaceId): int
    {
        $clientIds = Matter::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspaceId)
            ->distinct()
            ->pluck('client_id')
            ->all();

        if ($clientIds === []) {
            return 0;
        }

        return ClientInvitation::query()
            ->whereIn('client_id', $clientIds)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);
    }
}
