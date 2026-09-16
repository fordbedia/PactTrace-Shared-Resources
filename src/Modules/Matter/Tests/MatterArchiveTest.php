<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Matter\Application\UseCases\ArchiveMatterHandler;
use PactTrackSDK\SharedResources\Modules\Matter\Application\UseCases\UnarchiveMatterHandler;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * See .claude/rules/matter.md, "Matter Archive / Restore" — archiving is
 * non-destructive and has no status restriction, mirroring the Document
 * module's own ArchiveDocumentHandlerTest.
 */
class MatterArchiveTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('matter-archive');
    }

    /* ── handlers ────────────────────────────────────────────────────── */

    #[DataProvider('everyStatus')]
    public function test_archive_handler_allows_archiving_regardless_of_status(string $status): void
    {
        $matter = Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'status' => $status,
            'archived_at' => null,
        ]);

        $archived = app(ArchiveMatterHandler::class)->handle($matter, $this->tenant['owner']);

        $this->assertNotNull($archived->archived_at);
        $this->assertSame($status, $archived->status);
        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $matter->provider_id,
            'user_id' => $this->tenant['owner']->id,
            'action' => 'matter.archived',
            'auditable_type' => Matter::class,
            'auditable_id' => $matter->id,
        ]);
    }

    public function test_unarchive_handler_clears_archived_at(): void
    {
        $matter = Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'archived_at' => now(),
        ]);

        $restored = app(UnarchiveMatterHandler::class)->handle($matter, $this->tenant['owner']);

        $this->assertNull($restored->archived_at);
        $this->assertDatabaseHas('audit_logs', [
            'provider_id' => $matter->provider_id,
            'action' => 'matter.unarchived',
            'auditable_type' => Matter::class,
            'auditable_id' => $matter->id,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function everyStatus(): array
    {
        return [
            'active' => ['active'],
            'on_hold' => ['on_hold'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
        ];
    }

    /* ── HTTP surface ────────────────────────────────────────────────── */

    public function test_archive_endpoint_sets_archived_at(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->tenant['matter'];

        $response = $this->postJson("/api/v1/matters/{$matter->public_id}/archive");

        $response->assertSuccessful();
        $this->assertNotNull($matter->fresh()->archived_at);
    }

    public function test_unarchive_endpoint_clears_archived_at(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->tenant['matter'];
        $matter->forceFill(['archived_at' => now()])->save();

        $response = $this->postJson("/api/v1/matters/{$matter->public_id}/unarchive");

        $response->assertSuccessful();
        $this->assertNull($matter->fresh()->archived_at);
    }

    public function test_archive_endpoint_requires_authentication(): void
    {
        $this->postJson("/api/v1/matters/{$this->tenant['matter']->public_id}/archive")
            ->assertStatus(401);
    }

    public function test_archive_endpoint_rejects_a_matter_belonging_to_a_different_provider(): void
    {
        $other = ProviderTenantScenario::make('matter-archive-other');
        Sanctum::actingAs($other['owner']);

        $this->postJson("/api/v1/matters/{$this->tenant['matter']->public_id}/archive")
            ->assertStatus(403);

        $this->assertNull($this->tenant['matter']->fresh()->archived_at);
    }

    /* ── listing ─────────────────────────────────────────────────────── */

    public function test_the_default_listing_excludes_archived_matters(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $archived = Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'archived_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/matters')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertContains($this->tenant['matter']->id, $ids);
        $this->assertNotContains($archived->id, $ids);
    }

    public function test_archived_1_returns_only_archived_matters(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $archived = Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'archived_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/matters?archived=1')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertContains($archived->id, $ids);
        $this->assertNotContains($this->tenant['matter']->id, $ids);
    }

    public function test_an_archived_matter_is_also_excluded_from_a_status_filtered_tab(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $archivedActive = Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'status' => 'active',
            'archived_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/matters?filter=active')->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertNotContains($archivedActive->id, $ids);
    }

    /* ── stat cards ──────────────────────────────────────────────────── */

    public function test_stat_cards_exclude_archived_matters(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $before = $this->getJson('/api/v1/matters/stats')->assertOk();

        Matter::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'workspace_id' => $this->tenant['workspace']->id,
            'client_id' => $this->tenant['client']->id,
            'status' => 'active',
            'archived_at' => now(),
        ]);

        $after = $this->getJson('/api/v1/matters/stats')->assertOk();

        $this->assertSame($before->json('total'), $after->json('total'));
        $this->assertSame($before->json('active'), $after->json('active'));
    }
}
