<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Workspace\Application\Repository\Ports;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * Port for reading and persisting workspaces.
 *
 * `create()`'s first caller is RegisterProvider, which creates a provider's
 * default workspace at signup — see that class for why one is needed at all
 * (without it, RequestWorkspaceContext's "provider's sole workspace" fallback
 * never resolves, and every workspace-scoped query, including the clients
 * list, silently narrows nothing).
 *
 * `forProvider()` backs the Account Settings "Deactivate Workspace" modal,
 * which lists every workspace a provider currently has. Soft-deleted
 * (deactivated) workspaces are excluded — the model's SoftDeletes scope does
 * that for free.
 */
interface WorkspaceRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Workspace;

    /**
     * Persist a partial change to an already-resolved workspace.
     *
     * A direct update of the given instance — not a create-or-update by key —
     * so the onboarding "finish setting up my sole workspace" path can never
     * mint a duplicate. Named `saveAttributes` rather than `update` to stay
     * clear of the SDK `RepositoryLayer::update(array, ?int)` signature the
     * Eloquent adapter inherits — same convention as `UserRepository`.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveAttributes(Workspace $workspace, array $data): Workspace;

    /**
     * Every active workspace belonging to the provider, name-ordered.
     *
     * @return Collection<int, Workspace>
     */
    public function forProvider(int $providerId): Collection;

    /**
     * Is `$workspaceId` a live (not soft-deleted) workspace owned by
     * `$providerId`?
     *
     * The tenant-safety check the login wiring and the workspace switcher both
     * need — same rule as RequestWorkspaceContext::belongsToActor(), exposed on
     * the port so callers outside a request context can reuse it.
     */
    public function belongsToProvider(int $workspaceId, int $providerId): bool;
}
