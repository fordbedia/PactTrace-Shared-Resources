<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\StripePriceCatalog;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;

/**
 * `subscription_schedule.created` / `.updated` / `.released` / `.canceled`.
 *
 * A downgrade started in the Stripe Customer Portal is NOT applied
 * immediately — Stripe defers it to the end of the billing period via a
 * subscription schedule (current price now, target price from the period
 * end). This handler mirrors that schedule onto `subscriptions.pending_plan`
 * / `pending_plan_effective_at` so PactTrack can enforce the pending (lower)
 * plan's limits straight away — see Domain\Services\EffectivePlan and
 * .claude/rules/plan.md, "Pending downgrade".
 *
 * `pending_plan` is cleared in two places: here on `released`/`canceled`
 * (the customer changed their mind before it ran), and in
 * {@see SyncSubscriptionFromStripe} on the executing
 * `customer.subscription.updated` (the live plan caught up).
 *
 * Only writes `pending_plan` for a genuine **downgrade** (target tier lower
 * than the current live plan) — Stripe never schedules an upgrade, and the
 * rank check keeps a stale phase from ever *raising* the effective plan.
 */
final class SyncScheduledPlanChange
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly StripePriceCatalog $prices,
    ) {
    }

    /** `subscription_schedule.created` / `.updated`. */
    public function handle(StripeWebhookEventData $event): void
    {
        $subscription = $this->resolveSubscription($event);

        if ($subscription === null) {
            return;
        }

        $status = (string) ($event->object['status'] ?? '');
        if (in_array($status, ['released', 'canceled', 'completed'], true)) {
            $this->clearPending($subscription);

            return;
        }

        $targetPhase = $this->lastPhase($event);
        $targetPriceId = (string) ($targetPhase['items'][0]['price']['id'] ?? $targetPhase['items'][0]['price'] ?? '');
        $targetPlan = $targetPriceId !== '' ? $this->prices->resolve($targetPriceId)?->plan : null;

        if ($targetPlan === null) {
            Log::info('Stripe webhook ignored: subscription schedule target price is not in the plan catalogue.', [
                'type' => $event->type,
                'target_price_id' => $targetPriceId,
            ]);

            return;
        }

        $currentPlan = Plan::tryFrom((string) $subscription->plan);

        if ($currentPlan !== null && ! $targetPlan->isLowerThan($currentPlan)) {
            // Not a downgrade (or the schedule was edited back up to the
            // current tier) — nothing to pre-enforce; drop any stale value.
            $this->clearPending($subscription);

            return;
        }

        $effectiveAt = ! empty($targetPhase['start_date'])
            ? Carbon::createFromTimestamp((int) $targetPhase['start_date'])
            : null;

        $subscription->forceFill([
            'pending_plan' => $targetPlan->value,
            'pending_plan_effective_at' => $effectiveAt,
        ]);
        $this->subscriptions->save($subscription);
    }

    /** `subscription_schedule.released` / `.canceled`. */
    public function clear(StripeWebhookEventData $event): void
    {
        $subscription = $this->resolveSubscription($event);

        if ($subscription !== null) {
            $this->clearPending($subscription);
        }
    }

    private function clearPending(Subscription $subscription): void
    {
        if ($subscription->pending_plan === null && $subscription->pending_plan_effective_at === null) {
            return;
        }

        $subscription->forceFill(['pending_plan' => null, 'pending_plan_effective_at' => null]);
        $this->subscriptions->save($subscription);
    }

    /** @return array<string, mixed> */
    private function lastPhase(StripeWebhookEventData $event): array
    {
        $phases = $event->object['phases'] ?? [];

        return is_array($phases) && $phases !== [] ? (array) $phases[array_key_last($phases)] : [];
    }

    private function resolveSubscription(StripeWebhookEventData $event): ?Subscription
    {
        $stripeSubscriptionId = (string) ($event->object['subscription'] ?? '');
        $customerId = (string) ($event->object['customer'] ?? '');

        $subscription = $this->subscriptions->findByStripeIdentifiers(
            $stripeSubscriptionId !== '' ? $stripeSubscriptionId : null,
            $customerId !== '' ? $customerId : null,
        );

        if ($subscription === null) {
            Log::warning('Stripe webhook ignored: subscription schedule matched no local Subscription.', [
                'type' => $event->type,
                'stripe_subscription_id' => $stripeSubscriptionId,
                'stripe_customer_id' => $customerId,
            ]);
        }

        return $subscription;
    }
}
