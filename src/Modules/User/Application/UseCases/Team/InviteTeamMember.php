<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team;

use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\SendTeamInvitationEmail;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Use case behind "Invite" on /dashboard/team.
 *
 * This class does NOT touch the `users` table — that was the bug. An invited
 * person has no password and has accepted nothing, so there is nothing to
 * insert into `users` yet (which also failed hard on the NOT NULL `password`
 * column). It writes a `team_invitations` row instead; the real `users` row is
 * created later by AcceptTeamInvitation.
 *
 * Re-inviting an already-pending email RESENDS rather than rejects: it
 * regenerates the token + expiry on the existing row. Rationale — the team UI
 * has an explicit "Resend invite" affordance for pending rows (which now has
 * its own endpoint too, see ResendTeamInvitation), a lost first email should
 * not be a dead end, and mutating the same row keeps exactly one live token
 * per address (no ambiguity about which link works).
 */
class InviteTeamMember
{
    public function __construct(
        private readonly TeamInvitationRepository $invitations,
        private readonly SendTeamInvitationEmail $mailer,
    ) {
    }

    /**
     * @param  array{email: string, role: string, title?: string|null}  $data
     */
    public function handle(array $data, User $actor): TeamInvitation
    {
        $email = Str::lower(trim($data['email']));
        $token = TeamInvitation::freshToken();
        $expiresAt = TeamInvitation::defaultExpiry();

        $existing = $this->invitations->findPendingByEmail($email, (int) $actor->provider_id);

        if ($existing !== null) {
            $invitation = $this->invitations->renew($existing, $token, $expiresAt);
        } else {
            $invitation = $this->invitations->create([
                'provider_id' => $actor->provider_id,
                'email' => $email,
                'title' => $data['title'] ?? null,
                'role' => $data['role'],
                'invited_by_user_id' => $actor->id,
                'token' => $token,
                'expires_at' => $expiresAt,
            ]);
        }

        // Best-effort delivery — never rolls back the invitation row. See
        // SendTeamInvitationEmail for the full contract.
        $this->mailer->send($invitation, $actor, resent: $existing !== null);

        AuditLog::create([
            'provider_id' => $actor->provider_id,
            'user_id' => $actor->id,
            'action' => 'user.invited',
            'auditable_type' => TeamInvitation::class,
            'auditable_id' => $invitation->id,
            'metadata' => [
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'resent' => $existing !== null,
            ],
        ]);

        return $invitation;
    }
}
