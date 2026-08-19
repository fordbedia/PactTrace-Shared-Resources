<?php

namespace PactTrackSDK\SharedResources\Modules\Matter\Application\Action;

use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Repository\MattersRepository;

class CreateMattersHandler
{
	public function __construct(public MattersRepository $repository)
	{}

	public function handle($data)
	{
		return $this->repository->upsert($data);
	}
}