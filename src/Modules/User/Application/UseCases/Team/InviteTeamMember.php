<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\TeamInvitationRepository;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\TeamInvitationController;
use PactTrackSDK\SharedResources\Modules\User\Mail\TeamInvitationEmailNotification;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Throwable;

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
 * has an explicit "Resend invite" affordance for pending rows, a lost first
 * email should not be a dead end, and mutating the same row keeps exactly one
 * live token per address (no ambiguity about which link works).
 */
class InviteTeamMember
{
    /** How long an invitation link stays valid. */
    private const EXPIRY_DAYS = 7;

    public function __construct(
        private readonly TeamInvitationRepository $invitations,
    ) {
    }

    /**
     * @param  array{email: string, role: string, title?: string|null}  $data
     */
    public function handle(array $data, User $actor): TeamInvitation
    {
        $email = Str::lower(trim($data['email']));
        $token = Str::random(40);
        $expiresAt = now()->addDays(self::EXPIRY_DAYS);

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

        // Deliver the invitation email — the provider-branded card from
        // resources/views/emails/team-invitation.blade.php, carrying the
        // token TeamInvitationController::accept() will redeem. Best-effort:
        // a mail-transport failure is logged and swallowed, never rolls back
        // the invitation row or fails the request — same contract as
        // Signature\...\RecordSignatureCompletionUseCase::notifyClient().
        $provider = $actor->relationLoaded('provider')
            ? $actor->provider
            : $actor->provider()->first();

        try {
            Mail::to($invitation->email)->send(new TeamInvitationEmailNotification(
                providerName: $provider?->business_name ?? (string) config('app.name', 'PactTrack'),
                primaryColor: $provider?->primary_color,
                logoUrl: $provider?->logo_path,
                invitedByName: (string) $actor->name,
                email: $invitation->email,
                role: $invitation->role->value,
                title: $invitation->title,
                acceptUrl: TeamInvitationController::acceptUrl($invitation->token),
                expiresAt: $invitation->expires_at?->toDayDateTimeString(),
            ));
        } catch (Throwable $e) {
            Log::warning('Team invitation email failed to send', [
                'invitation_id' => $invitation->id,
                'email' => $invitation->email,
                'resent' => $existing !== null,
                'error' => $e->getMessage(),
            ]);
        }

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
