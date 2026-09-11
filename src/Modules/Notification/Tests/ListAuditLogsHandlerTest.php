<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Notification\Tests;

use Illuminate\Support\Carbon;
use PactTrackSDK\SharedResources\Modules\Notification\Application\Action\ListAuditLogsHandler;
use PactTrackSDK\SharedResources\Modules\Notification\Application\DTO\AuditLogListData;
use PactTrackSDK\SharedResources\Modules\Notification\Models\AuditLog;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Class-level unit coverage for {@see ListAuditLogsHandler} — resolved from
 * the container (its real bound EloquentAuditLogRepository, real DB) and
 * called directly, bypassing HTTP/AuditLogController entirely. This is the
 * one piece of logic the handler owns per its own docblock: turning the
 * requesting tenant's Plan into a retention cutoff and handing it to the
 * repository. See .claude/rules/plan.md, "Where each limit is enforced
 * today" (audit log retention) and .claude/rules/notification.md.
 *
 * AuditLogControllerTest already exercises this class end-to-end through the
 * HTTP surface (Starter cutoff / unlimited Professional+Firm / filters /
 * pagination) — this file is the unit-level companion: it isolates
 * `handle()`'s own cutoff arithmetic (exact 90-day boundary, `>=`
 * inclusivity, "now" resolution) without a controller, a policy gate, or an
 * authenticated request in the way.
 */
class ListAuditLogsHandlerTest extends BaseTest
{
    private TestScenarioCollection $tenant;

    private ListAuditLogsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('list-audit-logs-handler');
        $this->handler = app(ListAuditLogsHandler::class);

        Carbon::setTestNow(Carbon::parse('2026-09-11 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function log(array $attributes = []): AuditLog
    {
        return AuditLog::factory()
            ->forProvider($this->tenant['provider'])
            ->byUser($this->tenant['owner'])
            ->create($attributes);
    }

    private function data(array $overrides = []): AuditLogListData
    {
        return new AuditLogListData(
            provider_id: $overrides['provider_id'] ?? $this->tenant['provider']->id,
            actions: $overrides['actions'] ?? [],
            from: $overrides['from'] ?? null,
            to: $overrides['to'] ?? null,
            search: $overrides['search'] ?? null,
            per_page: $overrides['per_page'] ?? 20,
            page: $overrides['page'] ?? null,
        );
    }

    public function test_starter_hides_rows_older_than_90_days(): void
    {
        $this->log(['action' => 'a.recent', 'created_at' => now()->subDays(5)]);
        $this->log(['action' => 'a.stale', 'created_at' => now()->subDays(91)]);

        $result = $this->handler->handle($this->data(), Plan::Starter);

        $this->assertSame(1, $result->total());
        $this->assertSame('a.recent', $result->items()[0]->action);
    }

    public function test_the_90_day_cutoff_is_inclusive_at_the_exact_boundary(): void
    {
        // retentionCutoff() computes now()->subDays(90); the repository
        // applies it as `created_at >= cutoff`. A row created at exactly that
        // instant must still be visible — only strictly older rows are cut.
        $this->log(['action' => 'a.on-boundary', 'created_at' => now()->subDays(90)]);
        $this->log(['action' => 'a.one-second-over', 'created_at' => now()->subDays(90)->subSecond()]);

        $result = $this->handler->handle($this->data(), Plan::Starter);

        $this->assertSame(1, $result->total());
        $this->assertSame('a.on-boundary', $result->items()[0]->action);
    }

    public function test_the_cutoff_is_computed_relative_to_the_current_time(): void
    {
        $this->log(['action' => 'a.now', 'created_at' => now()]);

        // Advance "now" by 100 days without creating any new rows — the same
        // row that was 0 days old is now 100 days old and must drop out.
        Carbon::setTestNow(now()->addDays(100));

        $result = $this->handler->handle($this->data(), Plan::Starter);

        $this->assertSame(0, $result->total());
    }

    public function test_professional_and_firm_apply_no_retention_cutoff(): void
    {
        $this->log(['action' => 'a.ancient', 'created_at' => now()->subDays(400)]);

        $professional = $this->handler->handle($this->data(), Plan::Professional);
        $firm = $this->handler->handle($this->data(), Plan::Firm);

        $this->assertSame(1, $professional->total());
        $this->assertSame(1, $firm->total());
    }

    public function test_handle_scopes_to_the_given_provider_only(): void
    {
        $this->log(['action' => 'a.mine']);

        $other = ProviderTenantScenario::make('list-audit-logs-handler-other');
        AuditLog::factory()->forProvider($other['provider'])->byUser($other['owner'])
            ->create(['action' => 'a.not-mine']);

        $result = $this->handler->handle($this->data(), Plan::Firm);

        $this->assertSame(1, $result->total());
        $this->assertSame('a.mine', $result->items()[0]->action);
    }

    public function test_handle_still_applies_the_dtos_own_filters_alongside_the_retention_cutoff(): void
    {
        $this->log(['action' => 'document.archived', 'created_at' => now()->subDays(5)]);
        $this->log(['action' => 'envelope.sent', 'created_at' => now()->subDays(5)]);
        // Within the actions filter but past the Starter retention window —
        // the two constraints are ANDed, not either/or.
        $this->log(['action' => 'document.archived', 'created_at' => now()->subDays(100)]);

        $result = $this->handler->handle(
            $this->data(['actions' => ['document.archived']]),
            Plan::Starter,
        );

        $this->assertSame(1, $result->total());
        $this->assertSame('document.archived', $result->items()[0]->action);
    }

    public function test_handle_orders_newest_first_and_paginates_per_the_dto(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->log(['action' => "a.{$i}", 'created_at' => now()->subMinutes(5 - $i)]);
        }

        $result = $this->handler->handle($this->data(['per_page' => 2, 'page' => 1]), Plan::Firm);

        $this->assertSame(5, $result->total());
        $this->assertSame(2, $result->perPage());
        $this->assertSame(3, $result->lastPage());
        $this->assertSame('a.5', $result->items()[0]->action);
        $this->assertSame('a.4', $result->items()[1]->action);
    }
}
