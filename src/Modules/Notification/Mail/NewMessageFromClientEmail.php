<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The immediate "your client just sent a message" email to a thread's one
 * staff member — dispatched from
 * Messaging\Application\Action\AppendMessageToThread on every client -> staff
 * message, gated on the recipient's `new_message_from_client` preference.
 *
 * Independent of StaffUnreadMessageReminderEmail: this one fires immediately,
 * that one is the delayed "still unread after 5 minutes" nudge, and they are
 * separate toggles on the /notification screen. See
 * .claude/rules/notification.md, "Notification::isset() gating at dispatch
 * sites", and .claude/rules/messaging.md.
 *
 * PactTrack-branded ("Variant D"). Scalars-only DTO shape — near-identical to
 * StaffUnreadMessageReminderEmail minus the "reminder" framing.
 */
class NewMessageFromClientEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $staffName,
        public string $clientName,
        public string $matterName,
        public string $threadSubject,
        public string $messagePreview,
        public string $ctaUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New message from ' . $this->clientName,
        );
    }

    public function content(): Content
    {
        $rows = [
            ['label' => 'Client', 'value' => $this->clientName],
        ];

        if ($this->matterName !== '') {
            $rows[] = ['label' => 'Matter', 'value' => $this->matterName];
        }

        $rows[] = ['label' => 'Subject', 'value' => $this->threadSubject];

        return new Content(
            view: 'notification::emails.system-notification',
            with: [
                'title' => 'New message from ' . $this->clientName,
                'icon' => '💬',
                'heading' => 'New message from ' . $this->clientName,
                'intro' => "Hi {$this->staffName}, {$this->clientName} sent you a message. Open PactTrack to read it in full and reply.",
                'rows' => $rows,
                'quote' => $this->messagePreview,
                'ctaLabel' => 'Open Messages',
                'ctaUrl' => $this->ctaUrl,
                'footnote' => 'You&rsquo;re receiving this because a client messaged a conversation assigned to you and you have &ldquo;New message from a client&rdquo; notifications on. Change this in Notification Preferences.',
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
