<?php

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Service\TeamMemberHandler;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * The merged /dashboard/team roster: accepted `users` rows + still-pending
 * `team_invitations` rows, shaped for TeamMemberResource.
 *
 * Two things are resolved here from the *acting user's* own role, not trusted
 * from the request:
 *
 *   - The provider account owner (`providers.owner_user_id`) is never in the
 *     result, for any viewer. The owner manages their own account on
 *     /profile / /account-settings, not this list.
 *   - When the caller is an Admin (not the Owner), the roster is clamped to
 *     Staff members only — no Owner, no other Admins — regardless of any
 *     `?filter=` the controller later applies.
 *
 * `$status` selects the tab: 'active' (default) folds in pending invitations;
 * 'archived' is soft-deactivated `users` rows only (an invitation was never an
 * active member).
 */
class ListTeamMembers
{
	public function __construct(
		private readonly TeamInvitationRepository $teamInvitationRepository,
		private readonly UserRepository $userRepository
	)
	{}

	public function handle(int $providerID, User $actor, string $status = 'active'): Collection
	{
		$users = $this->userRepository->all($providerID, $status);

		// Pending invitations belong only to the "active" tab — they were never
		// an active member, so they have nothing to show under "archived".
		$pendingUsers = $status === 'archived'
			? new EloquentCollection()
			: $this->teamInvitationRepository->allPending($providerID);

		$members = TeamMemberHandler::make($users, $pendingUsers)->mergeTeamMembers();

		$ownerUserId = (int) Provider::query()
			->whereKey($providerID)
			->value('owner_user_id');

		$actorIsAdmin = $actor->primaryRole() === Role::Admin;

		return $members
			->reject(fn ($member) => $this->isOwnerRow($member, $ownerUserId))
			->when(
				$actorIsAdmin,
				fn (Collection $rows) => $rows->filter(fn ($member) => $this->roleOf($member) === Role::Staff->value),
			)
			->values();
	}

	/**
	 * The provider owner is never a roster row — matched by the
	 * `providers.owner_user_id` fact, and by the spatie Owner role as a
	 * belt-and-suspenders catch for any stray owner-role login.
	 */
	private function isOwnerRow(User|TeamInvitation $member, int $ownerUserId): bool
	{
		return $member instanceof User
			&& ((int) $member->getKey() === $ownerUserId || $member->primaryRole() === Role::Owner);
	}

	/**
	 * The role string for a merged-list entry — a `users` row resolves it
	 * through spatie, an invitation carries it as an enum cast / string.
	 */
	private function roleOf(User|TeamInvitation $member): ?string
	{
		if ($member instanceof User) {
			return $member->primaryRole()?->value;
		}

		return $member->role instanceof Role ? $member->role->value : $member->role;
	}
}
