<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\ProviderRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\StripePriceCatalog;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;
use PactTrackSDK\SharedResources\SDK\Application\Ports\Transactional;

/**
 * `customer.subscription.created` and `customer.subscription.updated` — the
 * authoritative sync point (see .claude/rules/plan.md, "Stripe status").
 * Writes both `Subscription` and `providers.plan`/`trial_ends_at` in one
 * transaction — skipping the `providers` write is the one mistake that
 * would silently break the frontend's `ProtectedRoute` (it reads
 * `user.provider.plan`, not `Subscription` directly).
 */
final class SyncSubscriptionFromStripe
{
    /**
     * Every value written to `subscriptions.status` must be one of these —
     * it's a real DB enum column, not a free string. Stripe's `unpaid`
     * collapses into `past_due` (confirmed: no dunning-detail distinction is
     * needed on PactTrack's side yet); anything else Stripe might report
     * (`incomplete`, `incomplete_expired`, `paused`) is treated the same
     * way — "needs attention" rather than silently writing an invalid enum
     * value.
     */
    private const STATUS_MAP = [
        'trialing' => 'trialing',
        'active' => 'active',
        'past_due' => 'past_due',
        'unpaid' => 'past_due',
        'canceled' => 'canceled',
    ];

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly ProviderRepository $providers,
        private readonly StripePriceCatalog $prices,
        private readonly Transactional $transaction,
    ) {
    }

    public function handle(StripeWebhookEventData $event): void
    {
        $stripeSubscriptionId = (string) ($event->object['id'] ?? '');
        $customerId = (string) ($event->object['customer'] ?? '');

        if ($stripeSubscriptionId === '') {
            Log::warning('Stripe webhook ignored: subscription event carried no id.', ['type' => $event->type]);

            return;
        }

        $subscription = $this->subscriptions->findByStripeIdentifiers($stripeSubscriptionId, $customerId);

        if ($subscription === null) {
            Log::warning('Stripe webhook ignored: no local Subscription matches this customer/subscription.', [
                'type' => $event->type,
                'stripe_subscription_id' => $stripeSubscriptionId,
                'stripe_customer_id' => $customerId,
            ]);

            return;
        }

        $firstItem = $event->object['items']['data'][0] ?? [];
        $priceId = (string) ($firstItem['price']['id'] ?? '');
        $plan = $priceId !== '' ? $this->prices->resolve($priceId)?->plan : null;
        $status = self::STATUS_MAP[(string) ($event->object['status'] ?? '')] ?? 'past_due';
        // Stripe API version 2025-03-31 ("basil") moved `current_period_end`
        // off the Subscription and onto each Subscription *item*. Read the
        // subscription-level field for older payloads, fall back to the first
        // item's for newer ones — without this, `current_period_ends_at`
        // silently stays null on every sync (webhook and reconciliation alike).
        $currentPeriodEnd = $event->object['current_period_end']
            ?? ($firstItem['current_period_end'] ?? null);
        $currentPeriodEndsAt = ! empty($currentPeriodEnd)
            ? Carbon::createFromTimestamp((int) $currentPeriodEnd)
            : null;
        // Same subscription-level → item-level fallback as `current_period_end`
        // (the "basil" API move). Backs the per-billing-cycle e-signature count
        // in EloquentPlanUsageReader.
        $currentPeriodStart = $event->object['current_period_start']
            ?? ($firstItem['current_period_start'] ?? null);
        $currentPeriodStartsAt = ! empty($currentPeriodStart)
            ? Carbon::createFromTimestamp((int) $currentPeriodStart)
            : null;
        $trialEndsAt = ! empty($event->object['trial_end'])
            ? Carbon::createFromTimestamp((int) $event->object['trial_end'])
            : null;

        // A scheduled downgrade that has now executed — the live plan has
        // caught up to what `pending_plan` was pointing at, so clear it (and
        // its date) in the same write. `subscription_schedule.released` covers
        // the "cancelled before it ran" case; see SyncScheduledPlanChange.
        $pendingCleared = $plan !== null && $subscription->pending_plan === $plan->value;

        $subscriptionAttributes = array_filter([
            'plan' => $plan?->value,
            'status' => $status,
            'current_period_starts_at' => $currentPeriodStartsAt,
            'current_period_ends_at' => $currentPeriodEndsAt,
            'trial_ends_at' => $trialEndsAt,
            'stripe_subscription_id' => $stripeSubscriptionId,
            'stripe_customer_id' => $customerId !== '' ? $customerId : null,
            'stripe_price_id' => $priceId !== '' ? $priceId : null,
        ], static fn ($value): bool => $value !== null);

        if ($pendingCleared) {
            $subscriptionAttributes['pending_plan'] = null;
            $subscriptionAttributes['pending_plan_effective_at'] = null;
        }

        $providerAttributes = array_filter([
            'plan' => $plan?->value,
            'trial_ends_at' => $trialEndsAt,
        ], static fn ($value): bool => $value !== null);

        $this->transaction->run(function () use ($subscription, $subscriptionAttributes, $providerAttributes): void {
            $subscription->forceFill($subscriptionAttributes);
            $this->subscriptions->save($subscription);

            if ($providerAttributes !== []) {
                $provider = $subscription->provider;
                $provider->forceFill($providerAttributes);
                $this->providers->save($provider);
            }
        });
    }
}
