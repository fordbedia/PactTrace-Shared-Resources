<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Workspace\Policies;

use PactTraceSDK\SharedResources\Modules\User\Models\User;
use PactTraceSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTraceSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;
use PactTraceSDK\SharedResources\Modules\Workspace\Models\Workspace;

/**
 * A workspace carries `provider_id` directly, so the base policy's tenant check
 * needs no override.
 *
 * It carries no `client_id`, which makes it provider-internal. The client role
 * is granted no workspace permission at all, and would still be refused by
 * TenantScopedPolicy::visibleToActorsClient if one were ever added — a record
 * with a null `client_id` is denied to client users by construction. Both
 * halves are intended: a client sees the workspace's *wording* through the
 * portal, never the workspace record or the switcher.
 */
class WorkspacePolicy extends TenantScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->check($user, Permission::WorkspaceView);
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $this->check($user, Permission::WorkspaceView, $workspace);
    }

    /**
     * Creating has no record to scope against, so this checks the permission
     * only — the provider_id written to the new row must still be the actor's
     * own, which is the calling use case's job to enforce.
     */
    public function create(User $user): bool
    {
        return $this->check($user, Permission::WorkspaceCreate);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->check($user, Permission::WorkspaceUpdate, $workspace);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->check($user, Permission::WorkspaceDelete, $workspace);
    }
}
