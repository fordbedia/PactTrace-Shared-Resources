<?php

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Service;

use Illuminate\Database\Eloquent\Collection;

class TeamMemberHandler
{
	public function __construct(
		private readonly Collection $teamMembers,
		private readonly Collection $pendingMembers
	){}

	public static function make(Collection $teamMembers, Collection $pendingMembers): self
	{
		return new self($teamMembers, $pendingMembers);
	}

	public function mergeTeamMembers()
	{
		// `concat`, not `merge`: on an Eloquent collection `merge` keys by the
		// model's primary key, so a `team_invitations` row whose id happens to
		// match a `users` row's id would silently overwrite it. `concat`
		// appends positionally and keeps both.
		return $this->teamMembers->map(fn ($item) => tap($item, fn ($team) => $team->table = 'users'))
			->concat($this->pendingMembers->map(fn ($item) => tap($item, fn ($team) => $team->table = 'team_invitations')));
	}
}