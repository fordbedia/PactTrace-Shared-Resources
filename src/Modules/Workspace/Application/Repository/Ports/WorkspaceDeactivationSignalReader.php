<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports;

use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceDeactivationSignals;

/**
 * Reads the live-activity counts `WorkspaceDeactivationPolicy` needs for one
 * workspace: open matters, documents out for signature, non-terminal
 * envelopes, and unaccepted client invitations tied to the workspace.
 *
 * A port so `GetWorkspaceDeactivationEligibility` / `DeactivateWorkspace` can
 * be tested against a fake, and so the cross-module reads (Matter, Document,
 * Signature, Client) stay behind one seam — same shape as the User module's
 * `AccountDeletionSignalReader`.
 *
 * Implemented by
 * Infrastructure\Repositories\Eloquent\EloquentWorkspaceDeactivationSignals.
 */
interface WorkspaceDeactivationSignalReader
{
    public function read(int $workspaceId): WorkspaceDeactivationSignals;
}
