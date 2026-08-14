<?php

namespace PactTraceSDK\SharedResources\Modules\Client\Infrastructure\Repositories\Eloquent;

use PactTraceSDK\SharedResources\Modules\Client\Application\DTO\ClientData;
use PactTraceSDK\SharedResources\Modules\Client\Infrastructure\Repositories\BaseRepository;
use PactTraceSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientRepository;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;

class EloquentClientRepository extends BaseRepository implements ClientRepository
{
	public function upsert(ClientData $data): void
	{
		$this->model->updateOrCreate([
			'provider_id' => $data->provider_id,
			'user_id' => $data->user_id,
			'email' => $data->email,
		],[
			'name' => $data->name,
			'company_name' => $data->company_name,
		]);
	}

	public function makeModel(): string
	{
		return Client::class;
	}
}