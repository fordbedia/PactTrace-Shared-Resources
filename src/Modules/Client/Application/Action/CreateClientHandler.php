<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Application\Action;

use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;

class CreateClientHandler
{
	public function __construct(private ClientRepository $repository)
	{}

	public function handle(ClientData $data): Client
	{
		return $this->repository->upsert($data);
	}
}