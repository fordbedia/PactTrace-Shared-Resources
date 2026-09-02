<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceRepository;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Backs `GET /api/v1/workspaces` — the sidebar switcher, the Account Settings
 * "Deactivate Workspace" modal, and the `/workspaces` management screen.
 *
 * Thin over the repository: the provider scoping and name ordering live in the
 * adapter. `$includeDeactivated` (only the management screen passes it) is the
 * one knob — active-only otherwise, so every existing caller is unchanged.
 */
final class ListProviderWorkspaces
{
    public function __construct(private readonly WorkspaceRepository $workspaces)
    {
    }

    /**
     * @return Collection<int, Workspace>
     */
    public function handle(int $providerId, bool $includeDeactivated = false): Collection
    {
        return $includeDeactivated
            ? $this->workspaces->forProviderIncludingDeactivated($providerId)
            : $this->workspaces->forProvider($providerId);
    }
}
