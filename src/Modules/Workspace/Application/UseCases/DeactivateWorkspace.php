<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases;

use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceDeactivationSignalReader;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Exceptions\WorkspaceDeactivationBlockedException;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Exceptions\WorkspaceDeactivationConfirmationException;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Services\WorkspaceDeactivationPolicy;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceDeactivationBlocker;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * The Account Settings "Deactivate Workspace" action, after the modal's
 * confirmation.
 *
 * "Deactivate" is a soft delete (`Workspace` already `use SoftDeletes`): the
 * design's copy — "Your data is retained but the portal becomes unavailable
 * until reactivated" — is exactly what a soft delete gives. The workspace's
 * matters/documents/envelopes stay in the database, addressable via
 * `withTrashed()`/`acrossWorkspaces()`, and drop out of every scoped query
 * because `WorkspaceScope` no longer resolves them. No FK cascade fires on a
 * soft delete, so nothing else needs cleaning up.
 *
 * Three gates, in order — same shape as the User module's `DeleteOwnAccount`:
 *   1. No live activity (WorkspaceDeactivationPolicy) — re-checked here, not
 *      just in the modal pre-flight, so a blocker that appeared in between
 *      still stops it.
 *   2. The typed name matches the acting user's name (case-insensitive).
 *   3. The typed password verifies against the acting user's stored hash.
 *
 * The confirmation is the acting user's own name/password, not the
 * workspace's — matching "the system will ask his/her Name and password".
 */
final class DeactivateWorkspace
{
    public function __construct(
        private readonly WorkspaceDeactivationSignalReader $reader,
        private readonly Hasher $hasher,
    ) {
    }

    /**
     * @throws WorkspaceDeactivationBlockedException
     * @throws WorkspaceDeactivationConfirmationException
     */
    public function handle(User $actor, Workspace $workspace, string $confirmationName, string $password): void
    {
        // The primary workspace can never be deactivated, whatever its
        // activity — short-circuit before the signal reader is even touched.
        if ($workspace->is_primary) {
            throw new WorkspaceDeactivationBlockedException(
                [WorkspaceDeactivationBlocker::IsPrimaryWorkspace]
            );
        }

        $blockers = WorkspaceDeactivationPolicy::blockers(
            $this->reader->read((int) $workspace->getKey())
        );

        if ($blockers !== []) {
            throw new WorkspaceDeactivationBlockedException($blockers);
        }

        if (Str::lower(trim($confirmationName)) !== Str::lower(trim((string) $actor->name))) {
            throw new WorkspaceDeactivationConfirmationException('name');
        }

        if (! $this->hasher->check($password, (string) $actor->password)) {
            throw new WorkspaceDeactivationConfirmationException('password');
        }

        DB::transaction(function () use ($actor, $workspace): void {
            $type = $workspace->workspace_type instanceof WorkspaceType
                ? $workspace->workspace_type->value
                : (string) $workspace->workspace_type;

            $workspace->delete();

            AuditLog::create([
                'provider_id' => $workspace->provider_id,
                'user_id' => $actor->id,
                'action' => 'workspace.deactivated',
                'auditable_type' => Workspace::class,
                'auditable_id' => $workspace->getKey(),
                'metadata' => [
                    'name' => $workspace->name,
                    'workspace_type' => $type,
                ],
            ]);
        });
    }
}
