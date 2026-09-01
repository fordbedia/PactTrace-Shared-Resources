<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Domain\Exceptions;

use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceDeactivationBlocker;
use RuntimeException;

/**
 * Thrown by `DeactivateWorkspace` when the pre-flight
 * `WorkspaceDeactivationPolicy` check finds live activity. The controller
 * renders it as a 422 with the blocker list, which the "Deactivate Workspace"
 * modal shows in place of the confirmation form.
 */
final class WorkspaceDeactivationBlockedException extends RuntimeException
{
    /**
     * @param  list<WorkspaceDeactivationBlocker>  $blockers
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('This workspace cannot be deactivated while it has live activity.');
    }
}
