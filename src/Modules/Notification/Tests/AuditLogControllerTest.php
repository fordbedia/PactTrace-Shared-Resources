<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Tests;

use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP coverage for AuditLogController — the read-only compliance audit-trail
 * surface at `GET /api/v1/audit-logs`. See .claude/rules/notification.md.
 *
 * The load-bearing assertion here is tenant isolation: AuditLogPolicy::viewAny()
 * only checks the `audit-log.view` permission, so if the repository query ever
 * loses its `where('provider_id', ...)` scope, one tenant would see another's
 * audit trail and the policy would not catch it. test_index_only_returns_the_
 * actors_own_tenant is that regression guard.
 *
 * Registers SanctumServiceProvider and authenticates via Sanctum::actingAs() —
 * same reasoning as MattersControllerTest: the route sits behind real
 * `auth:sanctum` and BaseTest's shared harness only configures the `web` guard.
 */
class AuditLogControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;
    use WithFaker;

    private TestScenarioCollection $tenant;

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

        $this->tenant = ProviderTenantScenario::make('audit-log');
    }

    private function log(array $attributes = []): AuditLog
    {
        return AuditLog::factory()
            ->forProvider($this->tenant['provider'])
            ->byUser($this->tenant['owner'])
            ->create($attributes);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/audit-logs')->assertStatus(401);
    }

    public function test_a_client_role_user_is_denied(): void
    {
        Sanctum::actingAs($this->tenant['clientUser']);

        $this->getJson('/api/v1/audit-logs')->assertStatus(403);
    }

    public function test_staff_and_owner_may_view(): void
    {
        $this->log(['action' => 'document.archived']);

        Sanctum::actingAs($this->tenant['staff']);
        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->tenant['owner']);
        $this->getJson('/api/v1/audit-logs')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_index_only_returns_the_actors_own_tenant(): void
    {
        $this->log(['action' => 'document.archived']);
        $this->log(['action' => 'envelope.sent']);

        $other = ProviderTenantScenario::make('audit-log-other');
        AuditLog::factory()->forProvider($other['provider'])->byUser($other['owner'])
            ->create(['action' => 'document.deleted']);

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/audit-logs')->assertOk();

        $response->assertJsonCount(2, 'data');
        foreach ($response->json('data') as $row) {
            $this->assertNotSame('document.deleted', $row['action']);
        }
    }

    public function test_system_rows_never_appear_in_a_tenant_listing(): void
    {
        $this->log(['action' => 'document.archived']);
        AuditLog::factory()->system()->create(['action' => 'subscription.trial_expired']);

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/audit-logs')->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('document.archived', $response->json('data.0.action'));
    }

    public function test_pagination_meta_and_second_page_slice(): void
    {
        // Newest first — create in a known order so the slice is predictable.
        for ($i = 1; $i <= 25; $i++) {
            $this->log([
                'action' => 'document.archived',
                'created_at' => now()->subMinutes(25 - $i),
            ]);
        }

        Sanctum::actingAs($this->tenant['owner']);

        $page1 = $this->getJson('/api/v1/audit-logs?per_page=10&page=1')->assertOk();
        $page1->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 10);

        $page2 = $this->getJson('/api/v1/audit-logs?per_page=10&page=2')->assertOk();
        $page2->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 2);

        $page1Ids = array_column($page1->json('data'), 'id');
        $page2Ids = array_column($page2->json('data'), 'id');
        $this->assertEmpty(array_intersect($page1Ids, $page2Ids));

        $this->getJson('/api/v1/audit-logs?per_page=10&page=3')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_per_page_is_clamped(): void
    {
        $this->log();

        Sanctum::actingAs($this->tenant['owner']);

        $this->getJson('/api/v1/audit-logs?per_page=100000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_action_filter_narrows_the_result_set(): void
    {
        $this->log(['action' => 'document.archived']);
        $this->log(['action' => 'document.deleted']);
        $this->log(['action' => 'envelope.sent']);

        Sanctum::actingAs($this->tenant['owner']);

        $this->getJson('/api/v1/audit-logs?actions=document.archived')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'document.archived');

        $this->getJson('/api/v1/audit-logs?actions=document.archived,envelope.sent')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_date_range_filter_narrows_by_created_at(): void
    {
        $this->log(['action' => 'a.old', 'created_at' => now()->subDays(20)]);
        $this->log(['action' => 'a.mid', 'created_at' => now()->subDays(5)]);
        $this->log(['action' => 'a.new', 'created_at' => now()->subDay()]);

        Sanctum::actingAs($this->tenant['owner']);

        $from = now()->subDays(7)->toDateString();
        $to = now()->subDays(2)->toDateString();

        $response = $this->getJson("/api/v1/audit-logs?from={$from}&to={$to}")->assertOk();

        $response->assertJsonCount(1, 'data');
        $this->assertSame('a.mid', $response->json('data.0.action'));
    }

    public function test_search_matches_action_and_actor_name(): void
    {
        $this->tenant['owner']->forceFill(['name' => 'Rachel Harmon'])->save();
        $this->log(['action' => 'document.archived']);

        AuditLog::factory()->forProvider($this->tenant['provider'])
            ->byUser($this->tenant['staff'])
            ->create(['action' => 'envelope.voided']);

        Sanctum::actingAs($this->tenant['owner']);

        $this->getJson('/api/v1/audit-logs?search=archived')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'document.archived');

        $this->getJson('/api/v1/audit-logs?search=Rachel')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'document.archived');
    }

    public function test_resource_shape_for_user_and_system_rows(): void
    {
        $this->tenant['owner']->forceFill(['name' => 'Sarah Mitchell', 'title' => 'Attorney'])->save();

        $userRow = $this->log([
            'action' => 'document.archived',
            'auditable_type' => 'PactTrackSDK\\SharedResources\\Modules\\Document\\Models\\Document',
            'auditable_id' => 42,
            'ip_address' => '192.168.1.42',
            'metadata' => ['previous_status' => 'draft'],
        ]);

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/audit-logs')->assertOk();

        $response->assertJsonPath('data.0.id', $userRow->id)
            ->assertJsonPath('data.0.action', 'document.archived')
            ->assertJsonPath('data.0.is_system', false)
            ->assertJsonPath('data.0.user.name', 'Sarah Mitchell')
            ->assertJsonPath('data.0.user.title', 'Attorney')
            ->assertJsonPath('data.0.auditable_type_label', 'Document')
            ->assertJsonPath('data.0.auditable_id', 42)
            ->assertJsonPath('data.0.ip_address', '192.168.1.42')
            ->assertJsonPath('data.0.metadata.previous_status', 'draft');

        $this->assertArrayNotHasKey('provider_id', $response->json('data.0'));
    }

    public function test_action_types_endpoint_returns_distinct_actions_for_the_tenant(): void
    {
        $this->log(['action' => 'document.archived']);
        $this->log(['action' => 'document.archived']);
        $this->log(['action' => 'envelope.sent']);

        $other = ProviderTenantScenario::make('audit-log-actions-other');
        AuditLog::factory()->forProvider($other['provider'])->byUser($other['owner'])
            ->create(['action' => 'subscription.trial_expired']);

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/audit-logs/action-types')->assertOk();

        $this->assertSame(['document.archived', 'envelope.sent'], $response->json('data'));
    }

    public function test_action_types_endpoint_denies_a_client_user(): void
    {
        Sanctum::actingAs($this->tenant['clientUser']);

        $this->getJson('/api/v1/audit-logs/action-types')->assertStatus(403);
    }
}
