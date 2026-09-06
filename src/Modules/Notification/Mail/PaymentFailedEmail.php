<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the provider owner when Stripe reports `invoice.payment_failed`.
 * Dispatched from
 * User\Application\UseCases\Billing\RecordPaymentFailure, gated on the
 * owner's `invoice_overdue` preference. See .claude/rules/plan.md ("Stripe
 * status") and .claude/rules/notification.md, "Notification::isset() gating
 * at dispatch sites".
 *
 * PactTrack-branded ("Variant D"), never the provider's — same reasoning as
 * SignatureCompletedEmail. Scalars-only DTO shape.
 */
class PaymentFailedEmail extends Mailable
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
            subject: 'Payment failed for your PactTrack subscription',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'notification::emails.system-notification',
            with: [
                'title' => 'Payment failed',
                'icon' => '⚠️',
                'heading' => 'Your payment could not be processed',
                'intro' => "Hi {$this->recipientName}, we couldn't charge the card on file for your {$this->planLabel} subscription. Please update your payment method to keep your portal active.",
                'rows' => [
                    ['label' => 'Plan', 'value' => $this->planLabel],
                ],
                'ctaLabel' => 'Update payment method',
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
