<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a matter's assigned staff member (or the provider owner when none is
 * assigned) the moment their *client* uploads a document — a staff/teammate
 * upload does not trigger it. Dispatched from
 * Document\Application\Action\UploadDocumentAction, gated on the recipient's
 * `new_doc_uploaded` preference. See .claude/rules/notification.md,
 * "Notification::isset() gating at dispatch sites".
 *
 * PactTrack-branded ("Variant D — Generic System Notification"), never the
 * provider's branding — the audience is the provider's own team. Same
 * scalars-only DTO shape as StaffUnreadMessageReminderEmail so it stays
 * trivial to queue/serialize.
 */
class NewDocumentUploadedEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $uploaderName,
        public string $matterName,
        public string $documentName,
        public string $ctaUrl,
        // The workspace this document belongs to. Blank when the caller can't
        // resolve one (a legacy row with no workspace_id) — the row is then
        // omitted, same as `matterName`.
        public string $workspaceName = '',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New document from ' . $this->uploaderName,
        );
    }

    public function content(): Content
    {
        $rows = [
            ['label' => 'Document', 'value' => $this->documentName],
            ['label' => 'Uploaded by', 'value' => $this->uploaderName],
        ];

        if ($this->matterName !== '') {
            array_splice($rows, 1, 0, [['label' => 'Matter', 'value' => $this->matterName]]);
        }

        if ($this->workspaceName !== '') {
            $rows[] = ['label' => 'Workspace', 'value' => $this->workspaceName];
        }

        $intro = $this->matterName !== ''
            ? "Hi {$this->recipientName}, {$this->uploaderName} just uploaded a new document to {$this->matterName}."
            : "Hi {$this->recipientName}, {$this->uploaderName} just uploaded a new document.";

        return new Content(
            view: 'notification::emails.system-notification',
            with: [
                'title' => 'New document uploaded',
                'icon' => '📄',
                'heading' => 'New document uploaded',
                'intro' => $intro,
                'rows' => $rows,
                'ctaLabel' => 'View document',
                'ctaUrl' => $this->ctaUrl,
                'footnote' => 'You&rsquo;re receiving this because you&rsquo;re the contact on this matter and have &ldquo;New document uploaded&rdquo; notifications on. Change this in Notification Preferences.',
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
