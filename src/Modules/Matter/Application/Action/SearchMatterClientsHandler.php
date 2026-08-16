<?php

namespace PactTraceSDK\SharedResources\Modules\Matter\Application\Action;

use Illuminate\Database\Eloquent\Collection;
use PactTraceSDK\SharedResources\Modules\Matter\Application\DTO\ClientSearchData;
use PactTraceSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;

class SearchMatterClientsHandler
{
	public function __construct(private MattersRepository $repository)
	{}

	public function handle(ClientSearchData $data): Collection
	{
		return $this->repository->searchClientsForSelection($data->provider_id, $data->search, $data->limit);
	}
}
