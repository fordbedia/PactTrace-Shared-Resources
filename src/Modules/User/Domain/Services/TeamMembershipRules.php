<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Services;

use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\CannotModifyTeamMemberException;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The two structural invariants that hold for *both* "change a teammate's
 * role" and "remove a teammate", regardless of who is calling — enforced in
 * the use cases (not just the controller or the UI) so a stale page or a
 * direct API call cannot get past them:
 *
 *   1. The acting owner can never target themselves — no accidental
 *      self-demotion, no removing the last owner.
 *   2. The provider's account owner (`providers.owner_user_id`) is never a
 *      valid target — the owner role is not reassignable through this flow and
 *      the owner row is not removable through it. This is also why "at least
 *      one owner always exists" needs no separate counting guard: the schema
 *      allows exactly one owner per provider and this rule makes that one
 *      untouchable here.
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
}
