<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a matter's assigned staff member (or the provider owner when none is
 * assigned) when an Envelope reaches `completed` — i.e. every recipient on it
 * has signed. Dispatched from
 * Signature\Application\UseCases\RecordSignatureCompletionUseCase (the
 * envelope-`completed` branch — distinct from the `draft -> sent` branch that
 * emails the *client* under `document_ready_for_signature`), gated on the
 * recipient's `signature_completed` preference. See
 * .claude/rules/notification.md, "Notification::isset() gating at dispatch
 * sites".
 *
 * PactTrack-branded ("Variant D"), never the provider's — the audience is the
 * provider's own team. Scalars-only DTO shape.
 */
class SignatureCompletedEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $clientName,
        public string $matterName,
        public string $documentName,
        public string $ctaUrl,
        // Blank when unresolvable (legacy envelope with no workspace_id) — the
        // row is then omitted, same as `matterName`.
        public string $workspaceName = '',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Signature completed: ' . $this->documentName,
        );
    }

    public function content(): Content
    {
        $rows = [
            ['label' => 'Document', 'value' => $this->documentName],
            ['label' => 'Client', 'value' => $this->clientName],
        ];

        if ($this->matterName !== '') {
            $rows[] = ['label' => 'Matter', 'value' => $this->matterName];
        }

        if ($this->workspaceName !== '') {
            $rows[] = ['label' => 'Workspace', 'value' => $this->workspaceName];
        }

        return new Content(
            view: 'notification::emails.system-notification',
            with: [
                'title' => 'Signature completed',
                'icon' => '✅',
                'heading' => 'Signature completed',
                'intro' => "Hi {$this->recipientName}, every signer has finished — “{$this->documentName}” is fully signed.",
                'rows' => $rows,
                'ctaLabel' => 'View signature',
                'ctaUrl' => $this->ctaUrl,
                'footnote' => 'You&rsquo;re receiving this because you&rsquo;re the contact on this matter and have &ldquo;Signature completed&rdquo; notifications on. Change this in Notification Preferences.',
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
