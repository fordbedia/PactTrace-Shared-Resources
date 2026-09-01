<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team;

use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\CannotModifyTeamMemberException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Services\TeamMembershipRules;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Owner-only: replace a teammate's role with `admin` or `staff`.
 *
 * The controller has already run `Gate::authorize('manageMembers', ...)`
 * (owner identity, not just a permission) and confirmed `$member` is in the
 * acting owner's tenant. This class re-asserts the two per-target invariants
 * (TeamMembershipRules) so they hold even if that ever changes, then swaps the
 * role and writes the audit entry.
 *
 * `owner` and `client` are not reachable targets — the FormRequest restricts
 * the input to `admin|staff`, and TeamMembershipRules blocks the owner row
 * anyway. Owner handoff is a separate flow that does not exist yet.
 */
class ChangeTeamMemberRole
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @throws CannotModifyTeamMemberException  reason 'self' | 'owner'
     */
    public function handle(User $member, Role $newRole, User $actor): User
    {
        $ownerUserId = (int) Provider::query()
            ->whereKey($member->provider_id)
            ->value('owner_user_id');

        TeamMembershipRules::assertModifiable($member, $actor, $ownerUserId);

        $previousRole = $member->primaryRole();

        // No-op guard: nothing to record if the role is unchanged.
        if ($previousRole === $newRole) {
            return $member;
        }

        $member = $this->users->syncRole($member, $newRole);

        AuditLog::create([
            'provider_id' => $member->provider_id,
            'user_id' => $actor->id,
            'action' => 'user.role_changed',
            'auditable_type' => User::class,
            'auditable_id' => $member->id,
            'metadata' => [
                'previous_role' => $previousRole?->value,
                'new_role' => $newRole->value,
            ],
        ]);

        return $member;
    }
}
