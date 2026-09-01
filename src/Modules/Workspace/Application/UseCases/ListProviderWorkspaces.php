<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceRepository;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Backs `GET /api/v1/workspaces` — the list the Account Settings "Deactivate
 * Workspace" modal shows, one row per active workspace with its own Deactivate
 * button.
 *
 * Thin over the repository: the provider scoping and name ordering live in the
 * adapter, and deactivated workspaces are already excluded by SoftDeletes.
 */
final class ListProviderWorkspaces
{
    public function __construct(private readonly WorkspaceRepository $workspaces)
    {
    }

    /**
     * @return Collection<int, Workspace>
     */
    public function handle(int $providerId): Collection
    {
        return $this->workspaces->forProvider($providerId);
    }
}
