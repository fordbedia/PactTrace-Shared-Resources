<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Repository;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;

interface ClientRepository
{
	public function upsert(ClientData $data): Client;

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