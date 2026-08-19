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
        $endingSoon = $due->reject(fn (Subscription $s) => $s->trial_ends_at->isPast());

        if ($expired->isNotEmpty()) {
            $this->subscriptions->markExpired($expired->pluck('id')->all());

            foreach ($expired as $subscription) {
                $this->logAuditEvent($subscription, 'subscription.trial_expired');
            }
        }

        // Real delivery (Resend/Postmark) is deliberately deferred — see
        // CLAUDE.md "Third-party services" and .claude/rules/notification.md.
        // Once wired, dispatch an actual Notification per $endingSoon entry
        // here instead of only recording that the scan saw it.
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
