<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\UseCases;

use Illuminate\Support\Carbon;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;

/**
 * Use case behind the daily `subscriptions:notify-trial-ending` scan (see
 * Console/Commands/NotifyTrialEnding, scheduled in backend/routes/console.php).
 *
 * One query (SubscriptionRepository::dueForTrialCheck) pulls every trialing
 * subscription due for *some* action — either it already expired, or it
 * expires within the warning window — then this class splits that single
 * result set into the two buckets by comparing trial_ends_at in memory,
 * rather than running the query twice.
 *
 * The "ending soon" bucket is further narrowed to `stripe_subscription_id
 * IS NULL` — a trial still on RegisterProvider's card-less sign-up default.
 * A trial that went through Stripe Checkout has its own authoritative
 * warning via the `customer.subscription.trial_will_end` webhook
 * (Application\UseCases\Billing\HandleTrialWillEnd); warning it here too
 * would be a second source of truth for the same "your trial is ending"
 * notice. The hard-expiry bucket below is deliberately NOT narrowed the same
 * way — it's a safety net against a missed webhook (same reasoning as
 * Signature's ReconcileStaleEnvelopes), so it still flips *any* trialing
 * subscription past its trial_ends_at, Stripe-backed or not.
 */
class ProcessTrialExpirations
{
    /** How many days out to start warning a trial is ending. */
    private const WARNING_WINDOW_DAYS = 3;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    /**
     * @return array{expired: int, ending_soon: int}
     */
    public function handle(): array
    {
        $now = Carbon::now();
        $due = $this->subscriptions->dueForTrialCheck($now->clone()->addDays(self::WARNING_WINDOW_DAYS));

        $expired = $due->filter(fn (Subscription $s) => $s->trial_ends_at->isPast());
        $endingSoon = $due->reject(fn (Subscription $s) => $s->trial_ends_at->isPast())
            ->reject(fn (Subscription $s) => $s->stripe_subscription_id !== null);

        if ($expired->isNotEmpty()) {
            $this->subscriptions->markExpired($expired->pluck('id')->all());

            foreach ($expired as $subscription) {
                $this->logAuditEvent($subscription, 'subscription.trial_expired');
            }
        }

        // Real delivery is Application\UseCases\Billing\HandleTrialWillEnd's
        // job for any trial with a Stripe subscription attached — see this
        // class's own docblock. What's left here (stripe_subscription_id ===
        // null) is the card-less sign-up trial, which still has no email
        // wired — recording the scan's own audit trail is what remains.
        foreach ($endingSoon as $subscription) {
            $this->logAuditEvent($subscription, 'subscription.trial_ending_soon');
        }

        return [
            'expired' => $expired->count(),
            'ending_soon' => $endingSoon->count(),
        ];
    }

    private function logAuditEvent(Subscription $subscription, string $action): void
    {
        AuditLog::create([
            'provider_id' => $subscription->provider_id,
            'user_id' => null, // system-initiated, not a user action
            'action' => $action,
            'auditable_type' => Subscription::class,
            'auditable_id' => $subscription->id,
            'metadata' => [
                'plan' => $subscription->plan,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            ],
        ]);
    }
}
