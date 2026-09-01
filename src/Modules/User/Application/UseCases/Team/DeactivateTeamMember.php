<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team;

use Illuminate\Support\Facades\DB;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\DepartingStaffReassignment;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\CannotModifyTeamMemberException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\TeamMembershipRules;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Owner-only: remove a teammate from the roster.
 *
 * "Remove" is a soft deactivation (`users.status = 'deactivated'`,
 * `deactivated_at = now`), never a hard `DELETE` — see
 * UserRepository::deactivate() for why (cascade-deletes would take out legal
 * documents, messages and the audit trail; `workspaces.owner_id` is
 * `restrictOnDelete`).
 *
 * The teammate's assigned matters are handed back to the owner
 * (DepartingStaffReassignment) — mirroring the schema's own
 * `assigned_staff_id`->`nullOnDelete()` intent, which never fires here because
 * the row is not deleted. Both writes run in one transaction so a matter is
 * never left reassigned against a still-active user, or vice versa.
 *
 * The controller has already authorised `manageMembers` (owner identity) and
 * tenant-scoped `$member`; TeamMembershipRules re-asserts "not yourself, not
 * the owner".
 */
class DeactivateTeamMember
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly DepartingStaffReassignment $reassignment,
    ) {
    }

    /**
     * @throws CannotModifyTeamMemberException  reason 'self' | 'owner'
     */
    public function handle(User $member, User $actor): User
    {
        $ownerUserId = (int) Provider::query()
            ->whereKey($member->provider_id)
            ->value('owner_user_id');

        TeamMembershipRules::assertModifiable($member, $actor, $ownerUserId);

        return DB::transaction(function () use ($member, $actor): User {
            $reassignedMatters = $this->reassignment->clearMatterAssignments((int) $member->id);

            $member = $this->users->deactivate($member);

            AuditLog::create([
                'provider_id' => $member->provider_id,
                'user_id' => $actor->id,
                'action' => 'user.deactivated',
                'auditable_type' => User::class,
                'auditable_id' => $member->id,
                'metadata' => [
                    'previous_role' => $member->primaryRole()?->value,
                    'reassigned_matters' => $reassignedMatters,
                ],
            ]);

            return $member;
        });
    }
}
