<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\PaymentFailedEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Notification\Support\Notification;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use Throwable;

/**
 * `invoice.payment_failed` — the "card declined" signal. Writes an audit
 * row and emails the provider owner, gated on `invoice_overdue`. Does NOT
 * flip `subscriptions.status` — Stripe fires `customer.subscription.updated`
 * alongside this for the same event, and that's the single writer of
 * `status` (see SyncSubscriptionFromStripe); handling both here too would
 * risk the two disagreeing. Best-effort mail, same contract as every other
 * internal-recipient notification in this codebase.
 */
final class RecordPaymentFailure
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
            Log::warning('Stripe webhook ignored: invoice.payment_failed matched no local Subscription.', [
                'stripe_customer_id' => $customerId,
            ]);

            return;
        }

        AuditLog::create([
            'provider_id' => $subscription->provider_id,
            'user_id' => null, // system-initiated, not a user action
            'action' => 'subscription.payment_failed',
            'auditable_type' => Subscription::class,
            'auditable_id' => $subscription->id,
            'metadata' => ['plan' => $subscription->plan],
        ]);

        try {
            $owner = $subscription->provider?->owner;

            if ($owner === null || ($owner->email ?? '') === '') {
                return;
            }

            if (! Notification::isset('invoice_overdue', $owner)) {
                Log::info('Payment-failed email skipped: invoice_overdue notification disabled.', [
                    'provider_id' => $subscription->provider_id,
                ]);

                return;
            }

            Mail::to($owner->email)->queue(new PaymentFailedEmail(
                recipientName: (string) $owner->name,
                planLabel: (Plan::tryFrom((string) $subscription->plan) ?? Plan::default())->label(),
                ctaUrl: rtrim((string) config('app.frontend_url'), '/') . '/dashboard/billing',
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
