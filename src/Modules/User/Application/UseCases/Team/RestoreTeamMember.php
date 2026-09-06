<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team;

use Illuminate\Support\Facades\DB;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\CannotModifyTeamMemberException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\TeamMembershipRules;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The inverse of DeactivateTeamMember: bring a soft-removed teammate back
 * (`users.status = 'active'`, `deactivated_at = null`).
 *
 * There is no DepartingStaffReassignment counterpart — restoring does not
 * retroactively undo the matter reassignment that deactivation applied; the
 * returning staffer is assigned new coverage going forward, not handed their
 * old matters back. This is a deliberate product choice, not an oversight.
 *
 * The controller has already authorised `changeMemberStatus` (Owner, or Admin)
 * and tenant-scoped `$member`; TeamMembershipRules re-asserts "not yourself,
 * not the owner, and — for a non-owner caller — the target is a Staff member".
 */
class RestoreTeamMember
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @throws CannotModifyTeamMemberException  reason 'self' | 'owner' | 'staff_only'
     */
    public function handle(User $member, User $actor): User
    {
        $ownerUserId = (int) Provider::query()
            ->whereKey($member->provider_id)
            ->value('owner_user_id');

        TeamMembershipRules::assertStatusChangeAllowed($member, $actor, $ownerUserId);

        return DB::transaction(function () use ($member, $actor): User {
            $member = $this->users->reactivate($member);

            AuditLog::create([
                'provider_id' => $member->provider_id,
                'user_id' => $actor->id,
                'action' => 'user.reactivated',
                'auditable_type' => User::class,
                'auditable_id' => $member->id,
                'metadata' => [
                    'role' => $member->primaryRole()?->value,
                ],
            ]);

            return $member;
        });
    }
}
