<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Action;

use Illuminate\Database\Eloquent\Collection;
use PactTrackSDK\SharedResources\Modules\Matter\Application\DTO\MatterSearchData;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;

class SearchMattersHandler
{
	public function __construct(private MattersRepository $repository)
	{}

	public function handle(MatterSearchData $data): Collection
	{
		return $this->repository->searchForSelection($data->provider_id, $data->search, $data->limit);
	}
}
