<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\PaymentRecoveredEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use Throwable;

/**
 * `invoice.paid` — used only to clear whatever "payment failed" state
 * `RecordPaymentFailure` set; the status flip itself is
 * `customer.subscription.updated`'s job (Stripe fires it alongside this for
 * the same recovery), so this only acts when the subscription's *currently
 * stored* status is still `past_due` — a normal renewal invoice on an
 * already-active subscription needs no audit row or email.
 *
 * Known limitation: Stripe doesn't guarantee delivery order between this
 * event and `customer.subscription.updated`. If the status webhook lands
 * first, this handler sees an already-`active` subscription and stays
 * silent — the recovery still happened correctly, PactTrack just doesn't
 * send the confirmation email for it. Not worth a dedicated "was this ever
 * past_due" column for a one-email edge case.
 */
final class ClearPaymentFailure
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    public function handle(StripeWebhookEventData $event): void
    {
        $stripeSubscriptionId = (string) ($event->object['subscription'] ?? '');
        $customerId = (string) ($event->object['customer'] ?? '');
        $subscription = $this->subscriptions->findByStripeIdentifiers($stripeSubscriptionId, $customerId);

        if ($subscription === null) {
            Log::warning('Stripe webhook ignored: invoice.paid matched no local Subscription.', [
                'stripe_customer_id' => $customerId,
            ]);

            return;
        }

        if ($subscription->status !== 'past_due') {
            return;
        }

        AuditLog::create([
            'provider_id' => $subscription->provider_id,
            'user_id' => null,
            'action' => 'subscription.payment_recovered',
            'auditable_type' => Subscription::class,
            'auditable_id' => $subscription->id,
            'metadata' => ['plan' => $subscription->plan],
        ]);

        try {
            $owner = $subscription->provider?->owner;

            if ($owner === null || ($owner->email ?? '') === '') {
                return;
            }

            if (! Notification::isset('payment_received', $owner)) {
                Log::info('Payment-recovered email skipped: payment_received notification disabled.', [
                    'provider_id' => $subscription->provider_id,
                ]);

                return;
            }

            Mail::to($owner->email)->queue(new PaymentRecoveredEmail(
                recipientName: (string) $owner->name,
                planLabel: (Plan::tryFrom((string) $subscription->plan) ?? Plan::default())->label(),
                ctaUrl: rtrim((string) config('app.frontend_url'), '/') . '/dashboard/billing',
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
