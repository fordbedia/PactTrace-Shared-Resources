<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team;

use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\SendTeamInvitationEmail;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\TeamInvitationNotAcceptableException;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * Use case behind the "Resend invite" row action on /dashboard/team.
 *
 * Distinct from re-running InviteTeamMember (which keys off the *email* and is
 * how a brand-new-or-pending address is invited): this acts on one already
 * resolved `team_invitations` row, chosen by the admin from the team table.
 * The controller has already checked the caller's `user.invite` permission and
 * that the row belongs to their tenant.
 *
 * Token rotation is the point, not a side effect. The old token is overwritten
 * in place (TeamInvitationRepository::renew), so a link that may be sitting in
 * a forwarded email is *dead* the moment this runs, not merely superseded —
 * there is never more than one live token for an address. Expiry is reset too,
 * so a lapsed-but-unaccepted invite becomes usable again; that's the whole
 * reason to resend one.
 */
class ResendTeamInvitation
{
    public function __construct(
        private readonly TeamInvitationRepository $invitations,
        private readonly SendTeamInvitationEmail $mailer,
    ) {
    }

    /**
     * @throws TeamInvitationNotAcceptableException  reason 'accepted' — the
     *         invitee already has a real login, so there is nothing to resend.
     *         (An expired-but-unaccepted invite is fine to resend.)
     */
    public function handle(TeamInvitation $invitation, User $actor): TeamInvitation
    {
        if ($invitation->isAccepted()) {
            throw TeamInvitationNotAcceptableException::forReason(
                TeamInvitationNotAcceptableException::REASON_ACCEPTED,
            );
        }

        $invitation = $this->invitations->renew(
            $invitation,
            TeamInvitation::freshToken(),
            TeamInvitation::defaultExpiry(),
        );

        $this->mailer->send($invitation, $actor, resent: true);

        AuditLog::create([
            'provider_id' => $invitation->provider_id,
            'user_id' => $actor->id,
            'action' => 'user.invited',
            'auditable_type' => TeamInvitation::class,
            'auditable_id' => $invitation->id,
            'metadata' => [
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'resent' => true,
            ],
        ]);

        return $invitation;
    }
}
