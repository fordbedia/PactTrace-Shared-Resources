<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Application\Action;

use Illuminate\Database\Eloquent\Collection;
use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientSearchData;
use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;

class SearchClientsHandler
{
	public function __construct(private ClientRepository $repository)
	{}

	public function handle(ClientSearchData $data): Collection
	{
		return $this->repository->searchForSelection($data->provider_id, $data->search, $data->limit);
	}
}
