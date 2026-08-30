<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Queries;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Matter\Application\Ports\Query\AssignableMatterStaff;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

class EloquentAssignableMatterStaff implements AssignableMatterStaff
{
    /**
     * @return list<string>
     */
    private function providerSideRoleNames(): array
    {
        return array_map(static fn (Role $r): string => $r->value, Role::providerSide());
    }

    public function forProvider(int $providerId): Collection
    {
        $ownerUserId = (int) Provider::query()->whereKey($providerId)->value('owner_user_id');

        return User::query()
            ->where('provider_id', $providerId)
            // spatie's role() scope — provider-side roles only, so a
            // client-role user carrying this provider_id is never listed.
            ->role($this->providerSideRoleNames())
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($ownerUserId): User {
                // Transient flag the picker uses to label "(Owner)" vs
                // "(Staff)"; not persisted.
                $user->is_owner = $user->id === $ownerUserId;

                return $user;
            })
            // Owner first, then staff by name.
            ->sortByDesc(fn (User $user): bool => (bool) $user->is_owner)
            ->values();
    }

    public function existsForProvider(int $userId, int $providerId): bool
    {
        return User::query()
            ->whereKey($userId)
            ->where('provider_id', $providerId)
            ->role($this->providerSideRoleNames())
            ->exists();
    }
}
