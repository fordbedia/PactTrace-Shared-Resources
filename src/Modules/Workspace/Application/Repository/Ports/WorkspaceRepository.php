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
     * Every workspace belonging to the provider — active AND deactivated
     * (soft-deleted) — name-ordered.
     *
     * Only the `/workspaces` management screen asks for this (via
     * `?include_deactivated=1`). Deliberately a separate method, not a flag on
     * `forProvider()`: the sidebar switcher and every other existing caller
     * want active-only and must not have to opt back out.
     *
     * @return Collection<int, Workspace>
     */
    public function forProviderIncludingDeactivated(int $providerId): Collection;

    /**
     * Resolve a workspace by id INCLUDING soft-deleted rows, or null.
     *
     * The restore endpoint acts on exactly the rows normal route-model binding
     * (and `forProvider()`) hide, so it needs this. The caller does the
     * cross-tenant `provider_id` check itself — this method does not scope by
     * provider, matching how `Workspace::find()` behaves elsewhere.
     */
    public function findWithTrashed(int $workspaceId): ?Workspace;

    /**
     * Un-deactivate a soft-deleted workspace (`restore()`), returning the fresh
     * instance. A no-op-safe call on a row that is not trashed.
     */
    public function restore(Workspace $workspace): Workspace;

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
