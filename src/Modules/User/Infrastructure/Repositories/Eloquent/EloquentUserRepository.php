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

	public function syncRole(User $user, Role $role): User
	{
		$user->syncRoles([$role->value]);

		return $user;
	}

	public function deactivate(User $user): User
	{
		$user->forceFill([
			'status' => 'deactivated',
			'deactivated_at' => now(),
		])->save();

		return $user;
	}

	/**
	 * The provider-side users (owner + admin + staff) backing /dashboard/team.
	 *
	 * A provider id is required in practice — omitting it (the historical
	 * signature) returns every user across every tenant and is only kept for
	 * back-compat. When given, the result is scoped to that provider AND to
	 * provider-side roles, so client-role logins never leak into the team list.
	 * The role set comes from Role::providerSide() (owner > admin > staff), not
	 * a local literal, so a new provider-side role can't silently be excluded
	 * here the way `admin` was.
	 */
	public function all(?int $providerId = null)
	{
		$query = $this->model->newQuery();

		if ($providerId !== null) {
			$providerSideRoles = array_map(
				static fn (Role $role): string => $role->value,
				Role::providerSide(),
			);

			$query->where('provider_id', $providerId)
				->whereHas('roles', function ($roleQuery) use ($providerSideRoles): void {
					$roleQuery->whereIn('name', $providerSideRoles);
				});
		}

		return $query->get();
	}
}
