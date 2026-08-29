<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a message thread's one staff member when a message their client sent
 * has gone unread for 5 minutes — dispatched from
 * Messaging\Jobs\SendStaffUnreadMessageReminder. See
 * .claude/rules/messaging.md, "Unread-message reminder email (staff)".
 *
 * Deliberately PactTrack-branded (the "Variant D — Generic System Notification"
 * look from Dashboard/PactTrack-Email-template.html), NOT provider-branded: the
 * audience is the provider's own staff in software they know is PactTrack, so
 * there is no relationship-branding purpose — and a Starter-tier provider may
 * have no branding configured at all. Same DTO-of-scalars pattern as
 * DocumentReadyForSignatureEmail so it stays trivial to queue/serialize.
 */
class StaffUnreadMessageReminderEmail extends Mailable
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
        return new Content(
            view: 'notification::emails.staff-unread-message-reminder',
            with: [
                'staffName' => $this->staffName,
                'clientName' => $this->clientName,
                'matterName' => $this->matterName,
                'subject' => $this->threadSubject,
                'messagePreview' => $this->messagePreview,
                'ctaUrl' => $this->ctaUrl,
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
