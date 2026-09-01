<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Domain\Services;

use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceDeactivationBlocker;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceDeactivationSignals;

/**
 * Decides whether a workspace may be deactivated, given a snapshot of its live
 * activity.
 *
 * Pure domain logic — no I/O. The "Deactivate Workspace" flow calls
 * `blockers()` twice: once for the modal's pre-flight (before it asks for
 * name/password), and again inside `DeactivateWorkspace` right before the
 * soft-delete, so a blocker that appeared in between still stops it. Same
 * two-call pattern as the User module's `AccountDeletionPolicy`.
 */
final class WorkspaceDeactivationPolicy
{
    /**
     * Every reason this workspace can't be deactivated right now, in display
     * order. An empty list means deactivation is permitted.
     *
     * @return list<WorkspaceDeactivationBlocker>
     */
    public static function blockers(WorkspaceDeactivationSignals $signals): array
    {
        $blockers = [];

        if ($signals->openMatterCount > 0) {
            $blockers[] = WorkspaceDeactivationBlocker::OpenMatters;
        }

        if ($signals->pendingDocumentCount > 0) {
            $blockers[] = WorkspaceDeactivationBlocker::PendingDocuments;
        }

        if ($signals->pendingEnvelopeCount > 0) {
            $blockers[] = WorkspaceDeactivationBlocker::PendingEnvelopes;
        }

        if ($signals->pendingClientInvitationCount > 0) {
            $blockers[] = WorkspaceDeactivationBlocker::PendingClientInvitations;
        }

        return $blockers;
    }

    public static function permitsDeactivation(WorkspaceDeactivationSignals $signals): bool
    {
        return self::blockers($signals) === [];
    }
}
