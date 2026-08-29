<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Infrastructure\Queries;

use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Port\Query\ProviderStaffDirectory;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

class EloquentProviderStaffDirectory implements ProviderStaffDirectory
{
    public function forProvider(int $providerId): Collection
    {
        return User::query()
            ->where('provider_id', $providerId)
            // spatie's role() scope — provider-side roles only, so a
            // client-role user carrying this provider_id (clients.user_id
            // links to a users row that does) is never listed.
            ->role(array_map(static fn (Role $r): string => $r->value, Role::providerSide()))
            ->orderBy('name')
            ->get();
    }
}
