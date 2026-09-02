<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports\WorkspaceRepository;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Create an additional workspace for a provider — the "Create workspace" item
 * in the sidebar switcher, and the plain (non-onboarding) entry to
 * `/dashboard/create-workspace`.
 *
 * Thin over the repository, exactly like RegisterProvider's own inline
 * `workspaces->create(...)` at sign-up. Blank `clientLabel` / `engagementLabel`
 * are left blank on purpose: Workspace's `creating()` hook fills them from the
 * chosen type's preset, and re-implementing that here would be a second place
 * for the two to drift.
 *
 * `provider_id` / `owner_id` come from the acting user in the controller, never
 * from request input — the caller's job per WorkspacePolicy::create's own note.
 */
final class CreateWorkspace
{
    public function __construct(private readonly WorkspaceRepository $workspaces)
    {
    }

    public function handle(
        int $providerId,
        int $ownerId,
        string $name,
        string $workspaceType,
        ?string $clientLabel = null,
        ?string $engagementLabel = null,
    ): Workspace {
        return $this->workspaces->create([
            'provider_id' => $providerId,
            'owner_id' => $ownerId,
            'name' => $name,
            'workspace_type' => $workspaceType,
            'client_label' => $clientLabel,
            'engagement_label' => $engagementLabel,
        ]);
    }
}
