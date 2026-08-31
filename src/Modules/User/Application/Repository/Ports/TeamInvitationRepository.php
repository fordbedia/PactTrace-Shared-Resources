<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports;

use DateTimeInterface;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;

/**
 * Port for persisting team (owner/staff) invitations.
 *
 * Implemented by
 * Infrastructure\Repositories\Eloquent\EloquentTeamInvitationRepository and
 * bound in UserProvider's $ports. Application code type-hints only this
 * interface so the use cases can be exercised against a fake.
 */
interface TeamInvitationRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): TeamInvitation;

    /**
     * Raw lookup by token — no validity filtering. The accept flow inspects
     * isPending() itself so it can tell "unknown link" (404) from "expired /
     * already used" (410).
     */
    public function findByToken(string $token): ?TeamInvitation;

    /**
     * A genuinely still-open invitation for this email in this tenant:
     * unaccepted AND unexpired. Used to decide whether a second invite to the
     * same address resends or is rejected.
     */
    public function findPendingByEmail(string $email, int $providerId): ?TeamInvitation;

    /**
     * Re-issue the link on an existing pending row (the "resend" path), so
     * re-inviting the same email never leaves two valid tokens outstanding —
     * only the newest one works.
     */
    public function renew(TeamInvitation $invitation, string $token, DateTimeInterface $expiresAt): TeamInvitation;

    public function markAccepted(TeamInvitation $invitation): TeamInvitation;
}
