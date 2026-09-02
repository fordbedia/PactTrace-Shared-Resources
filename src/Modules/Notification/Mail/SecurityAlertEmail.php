<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A personal security notice to the acting user — currently covers two events:
 *
 *   - `sign_in`          a new sign-in to their account
 *   - `password_changed` their password was changed
 *
 * Dispatched from User\Application\UseCases\Auth\NotifySuccessfulSignIn and
 * User\Application\UseCases\Profile\ChangeOwnPassword respectively. Gated on
 * the recipient's `security_alerts` preference — which is locked on for every
 * channel ("Required"), so the gate always passes today; it is applied anyway
 * so a future unlock needs no code change here. Two-factor events are out of
 * scope until a 2FA feature exists. See .claude/rules/notification.md,
 * "Notification::isset() gating at dispatch sites".
 *
 * PactTrack-branded ("Variant D"). One Mailable with an `$eventType` field
 * rather than two near-identical classes. Scalars-only DTO shape.
 */
class SecurityAlertEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $eventType,
        public string $occurredAt,
        public ?string $ipAddress,
        public string $ctaUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->eventType === 'password_changed'
                ? 'Your PactTrack password was changed'
                : 'New sign-in to your PactTrack account',
        );
    }

    public function content(): Content
    {
        $isPassword = $this->eventType === 'password_changed';

        $heading = $isPassword ? 'Your password was changed' : 'New sign-in to your account';

        $intro = $isPassword
            ? "Hi {$this->recipientName}, your PactTrack password was just changed. If this was you, no action is needed."
            : "Hi {$this->recipientName}, we noticed a new sign-in to your PactTrack account. If this was you, no action is needed.";

        $rows = [
            ['label' => 'Event', 'value' => $isPassword ? 'Password changed' : 'Sign-in'],
            ['label' => 'When', 'value' => $this->occurredAt],
        ];

        if ($this->ipAddress !== null && $this->ipAddress !== '') {
            $rows[] = ['label' => 'IP address', 'value' => $this->ipAddress];
        }

        return new Content(
            view: 'notification::emails.system-notification',
            with: [
                'title' => $heading,
                'icon' => '🔒',
                'heading' => $heading,
                'intro' => $intro,
                'rows' => $rows,
                'ctaLabel' => 'Review your account',
                'ctaUrl' => $this->ctaUrl,
                'footnote' => 'Security alerts are always on and can&rsquo;t be turned off. If you don&rsquo;t recognise this activity, change your password immediately.',
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
