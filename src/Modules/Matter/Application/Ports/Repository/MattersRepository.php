<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Repository;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface MattersRepository
{
	/**
	 * Clients selectable as the owner of a new matter — the "Search or select
	 * client…" field on the New Matter drawer. Scoped to the actor's tenant
	 * (`provider_id`) and, explicitly, to the current workspace via
	 * `Client::whereWorkspace()` — see .claude/rules/workspace.md. Deliberately
	 * fails closed (no current workspace ⇒ no clients) rather than falling
	 * back to `BelongsToWorkspace`'s default fail-open scope: this backs a
	 * live user pick for a workspace-scoped Matter, so leaking another
	 * workspace's clients into it would be a correctness bug, not just a
	 * background-job ambiguity.
	 */
	public function searchClientsForSelection(int $providerId, string $search, int $limit): Collection;

	/**
	 * Backs `/dashboard/matters`' filter chips (`all|active|on_hold|completed|
	 * cancelled`) — same shape as `ClientRepository::paginateAll/Active/...`
	 * (see .claude/rules/client.md). `provider_id` is the tenancy barrier;
	 * `BelongsToWorkspace` narrows further via its own global scope.
	 */
	public function paginateAll(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;

	public function paginateActive(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;

	public function paginateOnHold(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;

	public function paginateCompleted(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;

	public function paginateCancelled(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;
}