<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports;

use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Port for persisting a workspace.
 *
 * First caller is RegisterProvider, which creates a provider's default
 * workspace at signup — see that class for why one is needed at all (without
 * it, RequestWorkspaceContext's "provider's sole workspace" fallback never
 * resolves, and every workspace-scoped query, including the clients list,
 * silently narrows nothing).
 */
interface WorkspaceRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Workspace;
}
