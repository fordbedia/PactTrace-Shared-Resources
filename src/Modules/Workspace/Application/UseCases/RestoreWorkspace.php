<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases;

use Illuminate\Support\Facades\DB;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceRepository;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Reactivate (restore) a previously deactivated workspace — the "Reactivate"
 * row action on `/workspaces`.
 *
 * The mirror of `DeactivateWorkspace`: a soft delete is undone with
 * `restore()`, the workspace's matters/documents/envelopes become visible to
 * scoped queries again (nothing about them changed while it was trashed — no
 * FK cascade fires on a soft delete), and an `AuditLog` row records who did
 * it.
 *
 * Undoing a soft delete is not destructive, so — unlike deactivation — there
 * is no blocker pre-flight and no name/password confirmation. The
 * `restore` gate (permission `workspace.delete` + tenant) is the whole check.
 * Idempotent: restoring a row that is already active is a no-op that still
 * returns it (and writes no audit entry).
 */
final class RestoreWorkspace
{
    public function __construct(private readonly WorkspaceRepository $workspaces)
    {
    }

    public function handle(User $actor, Workspace $workspace): Workspace
    {
        if (! $workspace->trashed()) {
            return $workspace;
        }

        return DB::transaction(function () use ($actor, $workspace): Workspace {
            $type = $workspace->workspace_type instanceof WorkspaceType
                ? $workspace->workspace_type->value
                : (string) $workspace->workspace_type;

            $restored = $this->workspaces->restore($workspace);

            AuditLog::create([
                'provider_id' => $restored->provider_id,
                'user_id' => $actor->id,
                'action' => 'workspace.reactivated',
                'auditable_type' => Workspace::class,
                'auditable_id' => $restored->getKey(),
                'metadata' => [
                    'name' => $restored->name,
                    'workspace_type' => $type,
                ],
            ]);

            return $restored;
        });
    }
}
