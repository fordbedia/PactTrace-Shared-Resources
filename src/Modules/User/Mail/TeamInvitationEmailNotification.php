<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The email a newly-invited team member receives — dispatched from
 * {@see \PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Team\InviteTeamMember}.
 *
 * Design: the provider-branded card from
 * Dashboard/Client-facing-provider-email-template.html (the firm's own accent
 * colour + logo, PactTrack-powered footer), rendered by
 * `user::emails.team-invitation`.
 *
 * Constructor takes scalars only (serialisation-safe for the queue) — same
 * shape as the Notification module's Mailables. The `acceptUrl` is built by
 * {@see \PactTrackSDK\SharedResources\Modules\User\Http\Controllers\TeamInvitationController::acceptUrl()},
 * the controller that actually consumes the token on the other end.
 */
class TeamInvitationEmailNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $providerName,
        public ?string $primaryColor,
        public ?string $logoUrl,
        public string $invitedByName,
        public string $email,
        public string $role,
        public ?string $title,
        public string $acceptUrl,
        public ?string $expiresAt = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->invitedByName} invited you to join {$this->providerName} on PactTrack",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'user::emails.team-invitation',
            with: [
                'providerName' => $this->providerName,
                'primaryColor' => $this->primaryColor,
                'logoUrl' => $this->logoUrl,
                'invitedByName' => $this->invitedByName,
                'email' => $this->email,
                'roleLabel' => $this->role === 'owner' ? 'Administrator' : 'Team member',
                'title' => $this->title,
                'acceptUrl' => $this->acceptUrl,
                'expiresAt' => $this->expiresAt,
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
