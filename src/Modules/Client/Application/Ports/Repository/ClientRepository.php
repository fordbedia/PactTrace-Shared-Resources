<?php

namespace PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Repository;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;

interface ClientRepository
{
	public function upsert(ClientData $data): Client;

	/**
	 * Clients selectable when filing a document — the "Search or select
	 * client…" field on the Upload Documents modal (see
	 * .claude/rules/document.md). Text search over name/company_name/email,
	 * capped at $limit so a tenant with a very large client list never has
	 * every client loaded into one response.
	 */
	public function searchForSelection(int $providerId, string $search, int $limit): Collection;

	/**
	 * Links a client record to the login they just created by accepting
	 * their portal invitation, and flips them from `invited` to `active` in
	 * the same write — see AcceptClientInvitation.
	 */
	public function attachUser(int $clientId, int $userId): Client;

	public function paginateAll(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;

	public function paginateActive(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;

	public function paginateInvited(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;

	public function paginateArchived(int $providerId, int $perPage, ?int $page): LengthAwarePaginator;
}