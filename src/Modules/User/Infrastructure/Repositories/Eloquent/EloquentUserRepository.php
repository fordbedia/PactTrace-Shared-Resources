<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\Eloquent;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Repositories\BaseRepository;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

class EloquentUserRepository extends BaseRepository implements UserRepository
{
	public function makeModel(): string
	{
		return User::class;
	}

	public function create(array $data): User
	{
		return $this->model->create($data);
	}

	public function findByEmail(string $email): ?User
	{
		return $this->model->newQuery()->where('email', $email)->first();
	}

	public function emailExists(string $email): bool
	{
		return $this->isExists('email', $email);
	}

	public function assignProvider(User $user, int $providerId): User
	{
		$user->provider_id = $providerId;
		$user->save();

		return $user;
	}

	/**
	 * The one place spatie's role API is allowed to be called from. Everything
	 * upstream passes the Role enum; see the module rules doc — there is no
	 * `users.role` column, spatie is authoritative.
	 */
	public function assignRole(User $user, Role $role): User
	{
		$user->assignRole($role->value);

		return $user;
	}
}
