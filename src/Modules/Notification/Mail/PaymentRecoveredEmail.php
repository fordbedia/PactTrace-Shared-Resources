<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the provider owner when a Stripe `invoice.paid` event recovers a
 * subscription that was `past_due`. Dispatched from
 * User\Application\UseCases\Billing\ClearPaymentFailure, gated on the
 * owner's `payment_received` preference. See .claude/rules/plan.md and
 * .claude/rules/notification.md.
 */
class PaymentRecoveredEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $planLabel,
        public string $ctaUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment received — your PactTrack subscription is active',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'notification::emails.system-notification',
            with: [
                'title' => 'Payment received',
                'icon' => '✅',
                'heading' => 'Your account is back in good standing',
                'intro' => "Hi {$this->recipientName}, we've successfully charged your card and your {$this->planLabel} subscription is active again. Thanks for staying with PactTrack.",
                'rows' => [
                    ['label' => 'Plan', 'value' => $this->planLabel],
                ],
                'ctaLabel' => 'View billing',
                'ctaUrl' => $this->ctaUrl,
                'footnote' => 'You&rsquo;re receiving this because you&rsquo;re the billing contact for this PactTrack account. Change this in Notification Preferences.',
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
