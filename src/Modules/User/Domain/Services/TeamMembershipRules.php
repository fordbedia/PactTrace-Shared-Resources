<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Services;

use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\CannotModifyTeamMemberException;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The structural invariants that hold for the roster-mutating actions,
 * regardless of who is calling — enforced in the use cases (not just the
 * controller or the UI) so a stale page or a direct API call cannot get past
 * them:
 *
 *   1. The acting user can never target themselves — no accidental
 *      self-demotion, no removing the last owner.
 *   2. The provider's account owner (`providers.owner_user_id`) is never a
 *      valid target — the owner role is not reassignable through this flow and
 *      the owner row is not removable through it. This is also why "at least
 *      one owner always exists" needs no separate counting guard: the schema
 *      allows exactly one owner per provider and this rule makes that one
 *      untouchable here.
 *
 * `assertModifiable()` is the pair (1)+(2), shared by "change role" and by the
 * status-change actions alike. `assertStatusChangeAllowed()` layers a third
 * rule on top of the same two, for deactivate/restore only:
 *
 *   3. A non-owner caller (an Admin) may only deactivate/restore a Staff
 *      member. Role changes are unaffected — they stay owner-only at the
 *      policy level and never reach this method.
 */
final class TeamMembershipRules
{
    /**
     * @param  int  $ownerUserId  `providers.owner_user_id` for the tenant.
     *
     * @throws CannotModifyTeamMemberException
     */
    public static function assertModifiable(User $target, User $actor, int $ownerUserId): void
    {
        if ((int) $target->getKey() === (int) $actor->getKey()) {
            throw CannotModifyTeamMemberException::actingOnSelf();
        }

        if ((int) $target->getKey() === $ownerUserId) {
            throw CannotModifyTeamMemberException::targetIsOwner();
        }
    }

    /**
     * The rule for deactivate / restore: the two universal invariants above,
     * plus "a non-owner may only act on a Staff member".
     *
     * @param  int  $ownerUserId  `providers.owner_user_id` for the tenant.
     *
     * @throws CannotModifyTeamMemberException  reason 'self' | 'owner' | 'staff_only'
     */
    public static function assertStatusChangeAllowed(User $target, User $actor, int $ownerUserId): void
    {
        self::assertModifiable($target, $actor, $ownerUserId);

        if ((int) $actor->getKey() !== $ownerUserId && $target->primaryRole() !== Role::Staff) {
            throw CannotModifyTeamMemberException::targetNotStaff();
        }
    }
}
