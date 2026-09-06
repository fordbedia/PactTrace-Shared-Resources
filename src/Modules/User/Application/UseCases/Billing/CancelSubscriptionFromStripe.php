<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing;

use Illuminate\Support\Facades\Log;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;

/**
 * `customer.subscription.deleted`. Per the account-deletion policy
 * (.claude/rules/user.md — an *active* subscription blocks self-deletion,
 * `canceled` does not), this naturally unblocks account deletion once
 * synced; no separate check is needed since that policy already reads
 * `Subscription.status`.
 */
final class CancelSubscriptionFromStripe
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    public function handle(StripeWebhookEventData $event): void
    {
        $stripeSubscriptionId = (string) ($event->object['id'] ?? '');
        $customerId = (string) ($event->object['customer'] ?? '');
        $subscription = $this->subscriptions->findByStripeIdentifiers($stripeSubscriptionId, $customerId);

        if ($subscription === null) {
            Log::warning('Stripe webhook ignored: customer.subscription.deleted matched no local Subscription.', [
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);

            return;
        }

        $subscription->forceFill(['status' => 'canceled', 'canceled_at' => now()]);
        $this->subscriptions->save($subscription);
    }
}
