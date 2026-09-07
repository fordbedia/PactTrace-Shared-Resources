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
 *
 * `search()` is a second, additive entry point for the searchable sidebar
 * switcher — a capped, optionally name-filtered slice, primary-first. It is a
 * separate method rather than more parameters on `handle()` so the byte-for-byte
 * "no query params → unchanged" guarantee stays obvious: nothing that doesn't
 * call `search()` can be affected by it.
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

    /**
     * The switcher view: active workspaces only, primary first then alpha,
     * filtered by `$search` (case-insensitive substring on name, tenant-scoped)
     * and capped at `$limit`. The controller clamps `$limit` before it gets
     * here.
     *
     * @return Collection<int, Workspace>
     */
    public function search(int $providerId, ?string $search = null, ?int $limit = null): Collection
    {
        return $this->workspaces->search($providerId, $search, $limit);
    }
}
