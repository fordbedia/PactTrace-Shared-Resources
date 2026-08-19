<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Policies;

use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Authorization\TenantScopedPolicy;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Permission;

/**
 * The audit trail is read-only by design — there is no update or delete gate
 * here, and there should not be one. A compliance log that the application can
 * rewrite is not evidence of anything.
 *
 * Note that a system-initiated log row has a null `provider_id`, which the base
 * policy treats as belonging to no tenant and therefore denies to everyone.
 * Surfacing those is an operator concern, not a portal one.
 */
class AuditLogPolicy extends TenantScopedPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->check($user, Permission::AuditLogView);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->check($user, Permission::AuditLogView, $auditLog);
    }
}
