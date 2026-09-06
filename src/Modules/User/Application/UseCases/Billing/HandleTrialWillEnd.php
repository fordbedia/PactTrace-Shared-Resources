<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PactTrackSDK\SharedResources\Modules\Notification\Mail\TrialEndingSoonEmail;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use Throwable;

/**
 * `customer.subscription.trial_will_end` — replaces
 * ProcessTrialExpirations' "ending soon" bucket for any trial that went
 * through Stripe Checkout (see that class's own docblock, which deliberately
 * deferred real email delivery pending this). ProcessTrialExpirations keeps
 * warning card-less trials (RegisterProvider's sign-up default) — this
 * handler and that cron are scoped to two disjoint populations by
 * `stripe_subscription_id IS NULL`, so neither ever double-warns the same
 * trial. See .claude/rules/user.md.
 *
 * Reuses the same `subscription.trial_ending_soon` audit action string
 * ProcessTrialExpirations already writes, so both paths show up under one
 * action in the audit log regardless of which population triggered it.
 */
final class HandleTrialWillEnd
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
            Log::warning('Stripe webhook ignored: customer.subscription.trial_will_end matched no local Subscription.', [
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);

            return;
        }

        AuditLog::create([
            'provider_id' => $subscription->provider_id,
            'user_id' => null,
            'action' => 'subscription.trial_ending_soon',
            'auditable_type' => Subscription::class,
            'auditable_id' => $subscription->id,
            'metadata' => [
                'plan' => $subscription->plan,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            ],
        ]);

        try {
            $owner = $subscription->provider?->owner;

            if ($owner === null || ($owner->email ?? '') === '') {
                return;
            }

            // Not gated behind Notification::isset() — this is account-state
            // mail the owner can't opt out of, same as SecurityAlertEmail,
            // not a toggleable preference. See .claude/rules/notification.md.
            Mail::to($owner->email)->queue(new TrialEndingSoonEmail(
                recipientName: (string) $owner->name,
                planLabel: (Plan::tryFrom((string) $subscription->plan) ?? Plan::default())->label(),
                trialEndsAtLabel: $subscription->trial_ends_at?->toFormattedDateString() ?? 'soon',
                ctaUrl: rtrim((string) config('app.frontend_url'), '/') . '/dashboard/billing',
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
