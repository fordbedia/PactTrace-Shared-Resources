<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases;

use Illuminate\Contracts\Session\Session;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\Ports\CurrentWorkspace;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Make `$workspace` the actor's active workspace — backs
 * `POST /api/v1/workspaces/{workspace}/activate`, called by the sidebar
 * switcher and by `/dashboard/create-workspace` right after a create/update.
 *
 * Three writes, all for the same reason ("stay where I put myself"):
 *
 *   1. session `workspace_id` — what RequestWorkspaceContext reads on the next
 *      request (step 3 of its precedence).
 *   2. `users.default_workspace_id` — the same value as a column, so it also
 *      survives a fresh cookie / next sign-in. There is deliberately no
 *      separate "set as default" control; switching *is* how the default is
 *      set.
 *   3. CurrentWorkspace::setId() — pins it for the rest of *this* request too,
 *      so anything serialised after the switch already reflects it (the
 *      frontend then does a full reload regardless).
 *
 * The controller has already resolved `$workspace`, confirmed it is the
 * actor's tenant (404 otherwise) and authorised `view` on it, so this use case
 * does no checking of its own.
 */
final class SwitchActiveWorkspace
{
    public function __construct(
        private readonly Session $session,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {
    }

    public function handle(User $actor, Workspace $workspace): Workspace
    {
        $workspaceId = (int) $workspace->getKey();

        $this->session->put('workspace_id', $workspaceId);
        $actor->forceFill(['default_workspace_id' => $workspaceId])->save();
        $this->currentWorkspace->setId($workspaceId);

        return $workspace;
    }
}
