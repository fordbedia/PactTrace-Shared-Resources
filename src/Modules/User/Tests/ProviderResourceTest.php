<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Illuminate\Http\Request;
use PactTrackSDK\SharedResources\Modules\User\Http\Resources\ProviderResource;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\Subscription;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * ProviderResource is an allow-list nested in the authenticated user's
 * payload. This covers the subscription-derived fields specifically —
 * `current_period_ends_at` is what /dashboard/billing's Current Plan card
 * renders as "Renews …" (see .claude/rules/plan.md), and like
 * `subscription_status` / `has_stripe_subscription` it must only appear when
 * the `subscription` relation is actually loaded.
 */
class ProviderResourceTest extends BaseTest
{
    private function resolve(Provider $provider): array
    {
        return (new ProviderResource($provider))->resolve(Request::create('/'));
    }

    public function test_current_period_ends_at_is_exposed_as_iso8601_when_subscription_is_loaded(): void
    {
        $provider = Provider::factory()->create();
        Subscription::factory()->active()->create(['provider_id' => $provider->getKey()]);

        $data = $this->resolve($provider->load('subscription'));

        $this->assertArrayHasKey('current_period_ends_at', $data);
        $this->assertSame(
            $provider->subscription->current_period_ends_at->toIso8601String(),
            $data['current_period_ends_at'],
        );
        $this->assertTrue($data['has_stripe_subscription']);
    }

    public function test_current_period_ends_at_is_null_on_a_card_less_trial(): void
    {
        $provider = Provider::factory()->create();
        // Default SubscriptionFactory state: trialing, current_period_ends_at null.
        Subscription::factory()->create(['provider_id' => $provider->getKey()]);

        $data = $this->resolve($provider->load('subscription'));

        $this->assertArrayHasKey('current_period_ends_at', $data);
        $this->assertNull($data['current_period_ends_at']);
    }

    public function test_current_period_ends_at_is_absent_when_subscription_is_not_loaded(): void
    {
        $provider = Provider::factory()->create();
        Subscription::factory()->active()->create(['provider_id' => $provider->getKey()]);

        $data = $this->resolve($provider->fresh());

        $this->assertArrayNotHasKey('current_period_ends_at', $data);
        $this->assertArrayNotHasKey('subscription_status', $data);
    }

    /**
     * `capabilities` spreads `PlanInfo::toArray()` — the frontend reads
     * `allows_audit_log_export` off it to decide whether `/dashboard/audit-log`
     * shows the Export button (Firm only). See .claude/rules/plan.md.
     *
     * @param 'starter'|'professional'|'firm' $plan
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('auditLogExportByTier')]
    public function test_capabilities_carries_allows_audit_log_export_per_tier(string $plan, bool $expected): void
    {
        $provider = Provider::factory()->create(['plan' => $plan]);

        $data = $this->resolve($provider);

        $this->assertSame($expected, $data['capabilities']['allows_audit_log_export']);
    }

    public static function auditLogExportByTier(): array
    {
        return [
            'starter' => ['starter', false],
            'professional' => ['professional', false],
            'firm' => ['firm', true],
        ];
    }
}
