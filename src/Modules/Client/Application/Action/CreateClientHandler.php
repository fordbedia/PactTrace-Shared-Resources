<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Application\Action;

use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;

class CreateClientHandler
{
	public function __construct(private ClientRepository $repository)
	{}

	public function handle(ClientData $data)
	{
		$this->repository->upsert($data);
	}
}