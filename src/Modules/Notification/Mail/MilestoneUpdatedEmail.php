<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a matter's assigned staff member (or the provider owner when none is
 * assigned) when a milestone on that matter advances — either automatically
 * (Matter\Application\Services\MilestoneProgressionService::completeMilestone,
 * driven by a document upload or an envelope being sent/completed) or by a
 * manual matter-status edit (Matter\Application\Action\UpdateMattersHandler).
 * Both paths funnel through Matter\Application\Services\MilestoneNotifier and
 * are gated on the recipient's `milestone_updated` preference (default OFF).
 * See .claude/rules/notification.md, "Notification::isset() gating at dispatch
 * sites".
 *
 * PactTrack-branded ("Variant D"). Scalars-only DTO shape. `headline`/`detail`
 * are pre-composed by MilestoneNotifier so this Mailable stays copy-agnostic
 * about which of the two trigger paths produced it.
 */
class MilestoneUpdatedEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $matterName,
        public string $headline,
        public string $detail,
        public string $ctaUrl,
        // Blank when unresolvable — the row is then omitted.
        public string $workspaceName = '',
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->headline . ': ' . $this->matterName,
        );
    }

    public function content(): Content
    {
        $rows = [['label' => 'Matter', 'value' => $this->matterName]];

        if ($this->workspaceName !== '') {
            $rows[] = ['label' => 'Workspace', 'value' => $this->workspaceName];
        }

        $rows[] = ['label' => 'Update', 'value' => $this->detail];

        return new Content(
            view: 'notification::emails.system-notification',
            with: [
                'title' => 'Milestone updated',
                'icon' => '📍',
                'heading' => $this->headline,
                'intro' => "Hi {$this->recipientName}, {$this->detail}",
                'rows' => $rows,
                'ctaLabel' => 'Open matter',
                'ctaUrl' => $this->ctaUrl,
                'footnote' => 'You&rsquo;re receiving this because you&rsquo;re the contact on this matter and have &ldquo;Milestone updated&rdquo; notifications on. Change this in Notification Preferences.',
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
