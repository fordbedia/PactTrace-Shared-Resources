<?php

namespace PactTrackSDK\SharedResources\Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the provider owner when Stripe reports
 * `customer.subscription.trial_will_end` (~3 days before a Stripe-backed
 * trial ends). Dispatched from
 * User\Application\UseCases\Billing\HandleTrialWillEnd — this is what fills
 * the TODO ProcessTrialExpirations' "ending soon" bucket left deliberately
 * deferred (see that class's own docblock). Only ever fires for a trial that
 * went through Stripe Checkout; the card-less sign-up trial's warning stays
 * owned by ProcessTrialExpirations — see .claude/rules/user.md.
 *
 * Deliberately NOT gated behind a `Notification::isset()` catalogue key —
 * same reasoning as SecurityAlertEmail: this is account-state mail the owner
 * needs regardless of preference, not a toggleable notification. See
 * .claude/rules/notification.md, "transactional/onboarding mail... is not
 * gated... because it isn't in the catalogue."
 */
class TrialEndingSoonEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $recipientName,
        public string $planLabel,
        public string $trialEndsAtLabel,
        public string $ctaUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your PactTrack trial ends soon',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'notification::emails.system-notification',
            with: [
                'title' => 'Trial ending soon',
                'icon' => '⏰',
                'heading' => 'Your trial ends soon',
                'intro' => "Hi {$this->recipientName}, your {$this->planLabel} trial ends on {$this->trialEndsAtLabel}. Make sure your payment method is up to date to keep your portal active.",
                'rows' => [
                    ['label' => 'Plan', 'value' => $this->planLabel],
                    ['label' => 'Trial ends', 'value' => $this->trialEndsAtLabel],
                ],
                'ctaLabel' => 'Manage subscription',
                'ctaUrl' => $this->ctaUrl,
                'footnote' => 'You&rsquo;re receiving this because you&rsquo;re the billing contact for this PactTrack account.',
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
