<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Dashboard\Tests;

use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * HTTP coverage for `GET /api/v1/dashboard/recent-activity` — the "Recent
 * Activity" timeline. Provider-scoped, newest first, capped, and
 * system-initiated (null-`provider_id`) rows are never leaked in.
 */
class RecentActivityEndpointTest extends BaseTest
{
    use BuildsDashboardTenant;
    use LoadsModuleApiRoutes;

    private Provider $provider;
    private User $owner;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function moduleApiRoutes(): array
    {
        return [__DIR__ . '/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        [$this->provider, $this->owner] = $this->makeProviderTenant('recent-activity');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/dashboard/recent-activity')->assertStatus(401);
    }

    public function test_forbids_client_role_users(): void
    {
        Sanctum::actingAs($this->makeClientUser($this->provider, 'recent-activity'));

        $this->getJson('/api/v1/dashboard/recent-activity')->assertStatus(403);
    }

    public function test_returns_provider_scoped_events_newest_first_and_capped(): void
    {
        // Seven of this provider's events, oldest to newest.
        foreach (range(1, 7) as $i) {
            AuditLog::factory()->forProvider($this->provider)->byUser($this->owner)->create([
                'action' => "event.{$i}",
                'created_at' => Carbon::parse('2026-08-01')->addDays($i),
            ]);
        }

        // Noise that must be excluded.
        [$other, $otherOwner] = $this->makeProviderTenant('recent-activity-other');
        AuditLog::factory()->forProvider($other)->byUser($otherOwner)->create(['action' => 'other.tenant']);
        AuditLog::factory()->system()->create(['action' => 'system.no.tenant']);

        Sanctum::actingAs($this->owner);

        $rows = $this->getJson('/api/v1/dashboard/recent-activity')->assertOk()->json('data');

        $this->assertCount(5, $rows); // GetRecentActivityAction::LIMIT
        $this->assertSame(
            ['event.7', 'event.6', 'event.5', 'event.4', 'event.3'],
            array_column($rows, 'action'),
        );

        $actions = array_column($rows, 'action');
        $this->assertNotContains('other.tenant', $actions);
        $this->assertNotContains('system.no.tenant', $actions);
    }

    public function test_includes_this_providers_own_system_initiated_rows(): void
    {
        // A system row that DOES belong to this tenant (provider_id set,
        // user_id null) — e.g. a trial-expiry sweep — still belongs on the feed.
        AuditLog::factory()->forProvider($this->provider)->byUser(null)->create(['action' => 'subscription.trial_expired']);

        Sanctum::actingAs($this->owner);

        $rows = $this->getJson('/api/v1/dashboard/recent-activity')->assertOk()->json('data');

        $this->assertSame('subscription.trial_expired', $rows[0]['action']);
        $this->assertTrue($rows[0]['is_system']);
    }
}
