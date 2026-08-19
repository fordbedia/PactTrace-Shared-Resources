<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Policies;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;

class ClientPolicy extends TenantScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->check($user, Permission::ClientView);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->check($user, Permission::ClientView, $client);
    }

    public function create(User $user): bool
    {
        return $this->check($user, Permission::ClientCreate);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->check($user, Permission::ClientUpdate, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->check($user, Permission::ClientDelete, $client);
    }

    public function invite(User $user, Client $client): bool
    {
        return $this->check($user, Permission::ClientInvite, $client);
    }
}
