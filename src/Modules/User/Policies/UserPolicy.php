<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Policies;

use PactTrackSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Staff/user administration inside a provider.
 *
 * `invite` is a permission-only gate (like a `viewAny` or a bare `create`):
 * there is no record to scope against at invite time. Tenant isolation is not
 * skipped — InviteTeamMember reads the invitation's provider_id straight off
 * the acting user, so an owner can only ever invite into their own tenant. The
 * permission (`user.invite`, held by Owner only per Role::permissions()) is
 * what stops a staff user reaching the endpoint.
 */
class UserPolicy extends TenantScopedPolicy
{
    /**
     * List the tenant's team members (/dashboard/team). Permission-only, like
     * every `viewAny` — the index query itself is what scopes the rows to the
     * actor's provider (see ListTeamMembers). `user.view` is held by both
     * Owner and Staff per Role::permissions(), so any provider-side user can
     * see who is on the team; only `invite` (below) is owner-restricted.
     */
    public function viewAny(User $user): bool
    {
        return $this->check($user, Permission::UserView);
    }

    public function invite(User $user): bool
    {
        return $this->check($user, Permission::UserInvite);
    }
}
