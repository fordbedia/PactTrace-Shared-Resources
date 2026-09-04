<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports;

/**
 * Withdraws the still-open invitations tied to one workspace when it is
 * deactivated.
 *
 * A pending (unaccepted) invitation establishes no relationship and holds
 * nothing worth protecting, so `DeactivateWorkspace` no longer refuses while
 * one exists — it quietly stops the link working, the same as it lapsing.
 * "Scoped to the workspace" means a `client_invitations` row for a client that
 * has at least one matter in the workspace (client invitations carry no
 * `workspace_id` of their own — the matter is the link). Team invitations are
 * provider-level with no workspace link, so they are untouched here; the
 * account-deletion flow is where those are withdrawn.
 *
 * No email is sent to the invitee — the token simply stops resolving.
 *
 * Implemented by
 * Infrastructure\Repositories\Eloquent\EloquentWorkspaceInvitationCanceller.
 */
interface WorkspaceInvitationCanceller
{
    /**
     * Expire every unaccepted client invitation for a client with >= 1 matter
     * in this workspace. Idempotent. Returns how many rows were withdrawn.
     */
    public function expirePendingClientInvitationsForWorkspace(int $workspaceId): int;
}
