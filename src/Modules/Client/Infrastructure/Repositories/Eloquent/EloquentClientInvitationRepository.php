<?php

namespace PactTrackSDK\SharedResources\Modules\Client\Infrastructure\Repositories\Eloquent;

use PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Repository\ClientInvitationRepository;
use PactTrackSDK\SharedResources\Modules\Client\Infrastructure\Repositories\BaseRepository;
use PactTrackSDK\SharedResources\Modules\Client\Models\ClientInvitation;

class EloquentClientInvitationRepository extends BaseRepository implements ClientInvitationRepository
{
	public function makeModel(): string
	{
		return ClientInvitation::class;
	}

	public function create(array $attributes): ClientInvitation
	{
		/** @var ClientInvitation */
		return $this->model->create($attributes);
	}

	public function findValidByToken(string $token): ?ClientInvitation
	{
		return $this->model->newQuery()
			->where('token', $token)
			->whereNull('accepted_at')
			->where('expires_at', '>', now())
			->first();
	}

	public function markAccepted(ClientInvitation $invitation): void
	{
		$invitation->forceFill(['accepted_at' => now()])->save();
	}

	public function invalidatePendingForClient(int $clientId): void
	{
		$this->model->newQuery()
			->where('client_id', $clientId)
			->whereNull('accepted_at')
			->delete();
	}
}
