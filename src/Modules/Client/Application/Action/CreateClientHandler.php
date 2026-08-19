<?php

namespace PactTrackSDK\SharedResources\Modules\Client\Application\Action;

use PactTrackSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;

class CreateClientHandler
{
	public function __construct(private ClientRepository $repository)
	{}

	public function handle(ClientData $data): Client
	{
		return $this->repository->upsert($data);
	}
}