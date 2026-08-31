<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team;

use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;

/**
 * The read side of the accept flow: resolve a token to its invitation so the
 * accept-invitation page can show who invited the person, and to which
 * provider, before they set a password.
 *
 * Returns the raw row (or null) rather than deciding validity — the caller
 * needs to tell "unknown link" (404) from "expired" / "already used" (410 +
 * reason) itself, using TeamInvitation::unusableReason(). The provider relation
 * is eager-loaded here so the HTTP resource never lazy-loads.
 */
class GetTeamInvitation
{
    public function __construct(
        private readonly TeamInvitationRepository $invitations,
    ) {
    }

    public function handle(string $token): ?TeamInvitation
    {
        return $this->invitations->findByToken($token)?->loadMissing('provider');
    }
}
