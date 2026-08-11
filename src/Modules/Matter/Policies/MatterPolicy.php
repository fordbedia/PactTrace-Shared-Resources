<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Matter\Policies;

use PactTraceSDK\SharedResources\Modules\User\Models\User;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTraceSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;

class MatterPolicy extends TenantScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->check($user, Permission::MatterView);
    }

    public function view(User $user, Matter $matter): bool
    {
        return $this->check($user, Permission::MatterView, $matter);
    }

    /**
     * Pass the client the matter is being created for:
     *
     *     $user->can('create', [Matter::class, $client])
     *
     * Without it this can only check the permission, which would let a staff
     * user open a matter under another provider's client. Callers that create
     * a matter should always supply the client.
     */
    public function create(User $user, ?Client $client = null): bool
    {
        return $this->check($user, Permission::MatterCreate, $client);
    }

    public function update(User $user, Matter $matter): bool
    {
        return $this->check($user, Permission::MatterUpdate, $matter);
    }

    public function delete(User $user, Matter $matter): bool
    {
        return $this->check($user, Permission::MatterDelete, $matter);
    }
}
