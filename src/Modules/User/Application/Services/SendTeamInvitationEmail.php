<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\User\Http\Controllers\TeamInvitationController;
use PactTrackSDK\SharedResources\Modules\User\Mail\TeamInvitationEmailNotification;
use PactTrackSDK\SharedResources\Modules\User\Models\TeamInvitation;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Throwable;

/**
 * Delivers the team-invitation email.
 *
 * Extracted from InviteTeamMember so the resend path (ResendTeamInvitation)
 * sends the *exact same* Mailable through the *exact same* code: the
 * provider-branding lookup, the accept-URL builder, and the best-effort
 * "a mail-transport failure is logged and swallowed, never rolled back"
 * contract (same as
 * Signature\...\RecordSignatureCompletionUseCase::notifyClient()) all live
 * here once, not copy-pasted per use case.
 *
 * Application layer, not Infrastructure: the only I/O is delegated to the Mail
 * facade; everything else is "which fields go on the email".
 */
class SendTeamInvitationEmail
{
    /**
     * @param  bool  $resent  purely for the log line — the email itself is identical.
     */
    public function send(TeamInvitation $invitation, User $actor, bool $resent = false): void
    {
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
                'resent' => $resent,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
