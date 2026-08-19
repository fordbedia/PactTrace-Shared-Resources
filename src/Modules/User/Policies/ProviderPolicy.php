<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Policies;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

class ProviderPolicy extends TenantScopedPolicy
{
    public function view(User $user, Provider $provider): bool
    {
        return $this->check($user, Permission::ProviderView, $provider);
    }

    public function update(User $user, Provider $provider): bool
    {
        return $this->check($user, Permission::ProviderUpdate, $provider);
    }

    public function manageBranding(User $user, Provider $provider): bool
    {
        return $this->check($user, Permission::ProviderManageBranding, $provider);
    }

    public function manageBilling(User $user, Provider $provider): bool
    {
        return $this->check($user, Permission::ProviderManageBilling, $provider);
    }

    public function inviteStaff(User $user, Provider $provider): bool
    {
        return $this->check($user, Permission::UserInvite, $provider);
    }
}
