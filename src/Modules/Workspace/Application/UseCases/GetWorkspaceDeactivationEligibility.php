<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceDeactivationSignalReader;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceDeactivationSignals;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Backs `GET /api/v1/workspaces/{workspace}/deactivation-eligibility` — the
 * pre-flight the "Deactivate Workspace" modal runs before it renders the
 * name/password form for the chosen workspace.
 *
 * Returns the raw signal snapshot; the controller applies
 * `WorkspaceDeactivationPolicy` to turn it into the blocker list on the wire.
 * That split keeps the policy the single decision-maker (the same call is made
 * again inside `DeactivateWorkspace`).
 */
final class GetWorkspaceDeactivationEligibility
{
    public function __construct(
        private readonly WorkspaceDeactivationSignalReader $reader,
    ) {
    }

    public function handle(Workspace $workspace): WorkspaceDeactivationSignals
    {
        return $this->reader->read((int) $workspace->getKey());
    }
}
