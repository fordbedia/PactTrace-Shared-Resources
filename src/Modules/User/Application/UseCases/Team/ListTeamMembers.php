<?php

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\UserRepository;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Service\TeamMemberHandler;
use Illuminate\Database\Eloquent\Collection;

class ListTeamMembers
{
	public function __construct(
		private readonly TeamInvitationRepository $teamInvitationRepository,
		private readonly UserRepository $userRepository
	)
	{}

	public function handle(int $providerID): Collection
	{
		$pendingUser = $this->teamInvitationRepository->allPending($providerID);
		$user = $this->userRepository->all($providerID);

		$teamMembers = TeamMemberHandler::make($user, $pendingUser);

		return $teamMembers->mergeTeamMembers();
	}
}