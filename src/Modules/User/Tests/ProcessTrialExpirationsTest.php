<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\User\Tests;

use PactTraceSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTraceSDK\SharedResources\Modules\User\Application\UseCases\ProcessTrialExpirations;
use PactTraceSDK\SharedResources\Modules\User\Models\Provider;
use PactTraceSDK\SharedResources\Modules\User\Models\Subscription;
use PactTraceSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * Covers the daily scan behind `subscriptions:notify-trial-ending`
 * (Console/Commands/NotifyTrialEnding). The interesting property is that one
 * query result gets split into two different outcomes — see the class doc
 * on ProcessTrialExpirations.
 */
class ProcessTrialExpirationsTest extends BaseTest
{
    public function test_an_expired_trial_is_flipped_and_logged(): void
    {
        $provider = Provider::factory()->create();
        $subscription = Subscription::factory()->for($provider)->create([
            'status' => 'trialing',
            'trial_ends_at' => now()->subDays(2),
        ]);

        $result = $this->app->make(ProcessTrialExpirations::class)->handle();

        $this->assertSame(['expired' => 1, 'ending_soon' => 0], $result);
        $this->assertSame('expired', $subscription->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $provider->id,
            'action' => 'subscription.trial_expired',
            'auditable_type' => Subscription::class,
            'auditable_id' => $subscription->id,
        ]);
    }

    public function test_a_trial_ending_soon_is_logged_but_left_trialing(): void
    {
        $provider = Provider::factory()->create();
        $subscription = Subscription::factory()->for($provider)->create([
            'status' => 'trialing',
            'trial_ends_at' => now()->addDay(),
        ]);

        $result = $this->app->make(ProcessTrialExpirations::class)->handle();

        $this->assertSame(['expired' => 0, 'ending_soon' => 1], $result);
        $this->assertSame('trialing', $subscription->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $provider->id,
            'action' => 'subscription.trial_ending_soon',
            'auditable_id' => $subscription->id,
        ]);
    }

    public function test_a_trial_outside_the_warning_window_is_left_alone(): void
    {
        $provider = Provider::factory()->create();
        $subscription = Subscription::factory()->for($provider)->create([
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays(10),
        ]);

        $result = $this->app->make(ProcessTrialExpirations::class)->handle();

        $this->assertSame(['expired' => 0, 'ending_soon' => 0], $result);
        $this->assertSame('trialing', $subscription->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['auditable_id' => $subscription->id]);
    }

    public function test_an_already_active_subscription_past_its_old_trial_date_is_never_touched(): void
    {
        // A provider who converted off the trial: status moved to 'active' by
        // (eventually) a Stripe webhook, but trial_ends_at is untouched
        // history. The scan must not clobber status back to 'expired'.
        $provider = Provider::factory()->create();
        $subscription = Subscription::factory()->for($provider)->create([
            'status' => 'active',
            'trial_ends_at' => now()->subDays(30),
        ]);

        $result = $this->app->make(ProcessTrialExpirations::class)->handle();

        $this->assertSame(['expired' => 0, 'ending_soon' => 0], $result);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    public function test_markexpired_never_clobbers_a_status_a_webhook_already_moved_on(): void
    {
        // Defence in depth for the repository's own guard, independent of
        // ProcessTrialExpirations' in-memory filtering above.
        $provider = Provider::factory()->create();
        $subscription = Subscription::factory()->for($provider)->create(['status' => 'active']);

        app(\PactTraceSDK\SharedResources\Modules\User\Application\Repository\Ports\SubscriptionRepository::class)
            ->markExpired([$subscription->id]);

        $this->assertSame('active', $subscription->fresh()->status);
    }
}
