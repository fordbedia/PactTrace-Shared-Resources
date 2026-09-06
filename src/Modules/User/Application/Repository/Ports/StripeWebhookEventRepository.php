<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports;

/**
 * Idempotency check for inbound Stripe webhook deliveries — Stripe
 * redelivers events at least once, so every delivery must be recorded before
 * it's processed, not after. See .claude/rules/plan.md and the
 * create_stripe_webhook_events_table migration.
 *
 * Implemented by Infrastructure\Repositories\Eloquent\EloquentStripeWebhookEventRepository.
 */
interface StripeWebhookEventRepository
{
    /**
     * Records `$stripeEventId` if it hasn't been seen before.
     *
     * @return bool true when this is the first delivery (the caller should
     *              process it and then call {@see markProcessed()}); false
     *              when it's a replay (the caller should return 200 and do
     *              nothing else).
     */
    public function recordIfNew(string $stripeEventId, string $eventType): bool;

    public function markProcessed(string $stripeEventId): void;
}
