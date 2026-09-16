<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\Notification\Models\NotificationRead;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP coverage for NotificationFeedController — the notification bell in
 * AppShell's Topbar. See .claude/rules/notification.md.
 *
 * Two things here are load-bearing rather than incidental:
 *
 *  - **The feed is unread-only.** A row this user has read is excluded by
 *    the query itself (`paginateUnreadForUser`), not dimmed client-side, so
 *    "reading removes it" has to hold across a fresh request — that is what
 *    test_a_read_notification_never_comes_back asserts.
 *  - **`markRead` resolves the row inside the tenant.** `AuditLogPolicy::
 *    viewAny` is a bare permission check with no record, so it cannot catch
 *    a foreign `audit_logs` id on its own; without the controller's own
 *    lookup, any signed-in user holding `audit-log.view` could write a
 *    `notification_reads` row against another tenant's audit trail.
 *    test_mark_read_404s_on_another_tenants_audit_log is that regression
 *    guard.
 *
 * Registers SanctumServiceProvider and authenticates via Sanctum::actingAs()
 * — same reasoning as AuditLogControllerTest: these routes sit behind real
 * `auth:sanctum` and BaseTest's shared harness only configures `web`.
 */
class NotificationFeedControllerTest extends BaseTest
{
    use LoadsModuleApiRoutes;

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

        $this->tenant = ProviderTenantScenario::make('notification-feed');
    }

    private function log(array $attributes = []): AuditLog
    {
        return AuditLog::factory()
            ->forProvider($this->tenant['provider'])
            ->byUser($this->tenant['owner'])
            ->create($attributes);
    }

    /* ── index ────────────────────────────────────────────────────────── */

    public function test_the_feed_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    public function test_a_client_role_user_is_denied_the_feed(): void
    {
        Sanctum::actingAs($this->tenant['clientUser']);

        $this->getJson('/api/v1/notifications')->assertStatus(403);
    }

    public function test_an_empty_feed_is_an_empty_list_not_an_error(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_it_returns_this_tenants_audit_rows_newest_first(): void
    {
        $older = $this->log(['action' => 'document.archived', 'created_at' => now()->subDay()]);
        $newer = $this->log(['action' => 'envelope.sent', 'created_at' => now()]);

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(2, 'data');

        $this->assertSame($newer->id, $response->json('data.0.id'));
        $this->assertSame($older->id, $response->json('data.1.id'));
        $this->assertSame('envelope.sent', $response->json('data.0.action'));
        // Unread-only feed: every row it returns is unread by construction.
        $this->assertFalse($response->json('data.0.read'));
    }

    public function test_the_feed_never_returns_another_tenants_audit_rows(): void
    {
        $other = ProviderTenantScenario::make('notification-feed-other');

        AuditLog::factory()
            ->forProvider($other['provider'])
            ->byUser($other['owner'])
            ->create(['action' => 'document.deleted']);

        $mine = $this->log(['action' => 'document.archived']);

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_the_feed_pages_ten_at_a_time_and_reports_more(): void
    {
        foreach (range(1, 12) as $i) {
            $this->log(['action' => 'document.archived', 'created_at' => now()->subMinutes($i)]);
        }

        Sanctum::actingAs($this->tenant['owner']);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.has_more', true);

        $this->getJson('/api/v1/notifications?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', false);
    }

    /* ── mark read ────────────────────────────────────────────────────── */

    public function test_marking_one_read_records_it_for_that_user(): void
    {
        $log = $this->log(['action' => 'document.archived']);

        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson("/api/v1/notifications/{$log->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $log->id);

        $this->assertDatabaseHas('notification_reads', [
            'user_id' => $this->tenant['owner']->id,
            'audit_log_id' => $log->id,
        ]);
    }

    public function test_a_read_notification_never_comes_back(): void
    {
        $read = $this->log(['action' => 'document.archived']);
        $unread = $this->log(['action' => 'envelope.sent']);

        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson("/api/v1/notifications/{$read->id}/read")->assertOk();

        $response = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($unread->id, $response->json('data.0.id'));
    }

    public function test_read_state_is_per_user_not_per_tenant(): void
    {
        $log = $this->log(['action' => 'document.archived']);

        Sanctum::actingAs($this->tenant['owner']);
        $this->postJson("/api/v1/notifications/{$log->id}/read")->assertOk();

        // The same tenant's staff member has not read it, so it is still theirs to see.
        Sanctum::actingAs($this->tenant['staff']);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_marking_read_twice_is_idempotent(): void
    {
        $log = $this->log(['action' => 'document.archived']);

        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson("/api/v1/notifications/{$log->id}/read")->assertOk();
        $this->postJson("/api/v1/notifications/{$log->id}/read")->assertOk();

        $this->assertSame(1, NotificationRead::query()
            ->where('user_id', $this->tenant['owner']->id)
            ->where('audit_log_id', $log->id)
            ->count());
    }

    public function test_mark_read_404s_on_another_tenants_audit_log(): void
    {
        $other = ProviderTenantScenario::make('notification-feed-foreign');

        $foreign = AuditLog::factory()
            ->forProvider($other['provider'])
            ->byUser($other['owner'])
            ->create(['action' => 'document.deleted']);

        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson("/api/v1/notifications/{$foreign->id}/read")->assertStatus(404);

        $this->assertDatabaseMissing('notification_reads', [
            'user_id' => $this->tenant['owner']->id,
            'audit_log_id' => $foreign->id,
        ]);
    }

    public function test_mark_read_404s_on_an_audit_log_that_does_not_exist(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        // Would otherwise surface as a 500 from the foreign key.
        $this->postJson('/api/v1/notifications/999999/read')->assertStatus(404);
    }

    /* ── clear ────────────────────────────────────────────────────────── */

    public function test_clear_empties_the_feed(): void
    {
        $this->log(['action' => 'document.archived']);
        $this->log(['action' => 'envelope.sent']);

        Sanctum::actingAs($this->tenant['owner']);

        $this->getJson('/api/v1/notifications')->assertJsonCount(2, 'data');

        $this->postJson('/api/v1/notifications/clear')->assertOk();

        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_clear_only_clears_the_acting_users_own_feed(): void
    {
        $this->log(['action' => 'document.archived']);

        Sanctum::actingAs($this->tenant['owner']);
        $this->postJson('/api/v1/notifications/clear')->assertOk();

        Sanctum::actingAs($this->tenant['staff']);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_clear_leaves_another_tenants_rows_alone(): void
    {
        $other = ProviderTenantScenario::make('notification-feed-clear-other');

        $foreign = AuditLog::factory()
            ->forProvider($other['provider'])
            ->byUser($other['owner'])
            ->create(['action' => 'document.deleted']);

        $this->log(['action' => 'document.archived']);

        Sanctum::actingAs($this->tenant['owner']);
        $this->postJson('/api/v1/notifications/clear')->assertOk();

        // The other tenant's own owner still sees their row — clearing is
        // scoped to the acting user's provider, not global.
        Sanctum::actingAs($other['owner']);
        $response = $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame($foreign->id, $response->json('data.0.id'));
    }

    public function test_clearing_an_already_clear_feed_writes_nothing_further(): void
    {
        $this->log(['action' => 'document.archived']);

        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/notifications/clear')->assertOk();
        $before = NotificationRead::query()->count();

        $this->postJson('/api/v1/notifications/clear')->assertOk();

        // The INSERT ... SELECT excludes already-read rows, so a second
        // clear is a genuine no-op rather than a unique-index collision.
        $this->assertSame($before, NotificationRead::query()->count());
    }

    public function test_clear_requires_authentication(): void
    {
        $this->postJson('/api/v1/notifications/clear')->assertStatus(401);
    }
}
