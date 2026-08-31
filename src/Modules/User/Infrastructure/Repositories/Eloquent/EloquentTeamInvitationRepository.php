<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use DateTimeInterface;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\BaseRepository;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;

class EloquentTeamInvitationRepository extends BaseRepository implements TeamInvitationRepository
{
    public function makeModel(): string
    {
        return TeamInvitation::class;
    }

    public function create(array $data): TeamInvitation
    {
        return $this->model->create($data);
    }

    public function findByToken(string $token): ?TeamInvitation
    {
        return $this->model->newQuery()->where('token', $token)->first();
    }

    public function findPendingByEmail(string $email, int $providerId): ?TeamInvitation
    {
        return $this->model->newQuery()
            ->where('provider_id', $providerId)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();
    }

    public function renew(TeamInvitation $invitation, string $token, DateTimeInterface $expiresAt): TeamInvitation
    {
        $invitation->forceFill([
            'token' => $token,
            'expires_at' => $expiresAt,
        ])->save();

        return $invitation;
    }

    public function markAccepted(TeamInvitation $invitation): TeamInvitation
    {
        $invitation->forceFill(['accepted_at' => now()])->save();

        return $invitation;
    }

	public function allPending(int $providerId)
	{
		return $this->model->newQuery()
			->where('provider_id', $providerId)
			->whereNull('accepted_at')
			->where('expires_at', '>', now())
			->get();
	}
}
