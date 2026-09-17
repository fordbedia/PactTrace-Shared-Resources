<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * HTTP coverage for MattersController — previously nonexistent (see
 * EnvelopeDetailControllerTest's own docblock, and .claude/rules/matter.md),
 * added alongside `/dashboard/matters/{matterId}` becoming a real Next.js
 * route keyed by `Matter::public_id` rather than client-side view state.
 *
 * `show()` needed no code change to support this — `Matter::getRouteKeyName()`
 * already returns `public_id`, and `Route::apiResource('/matters', ...)`
 * relies on implicit route-model binding, so the endpoint has always resolved
 * by `public_id`. This class exists to actually assert that, and the
 * tenant-ownership check, now that a real page depends on both.
 *
 * Registers Laravel\Sanctum\SanctumServiceProvider itself and authenticates
 * via Sanctum::actingAs() — same reasoning as EnvelopeDetailControllerTest:
 * this route sits behind real `auth:sanctum`, and BaseTest's shared harness
 * only configures the `web` guard.
 */
class MattersControllerTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('matters-controller');
    }

    public function test_show_resolves_by_public_id(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->tenant['matter'];

        $response = $this->getJson("/api/v1/matters/{$matter->public_id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $matter->id)
            ->assertJsonPath('data.public_id', $matter->public_id)
            ->assertJsonPath('data.client.id', $this->tenant['client']->id);
    }

    public function test_show_404s_for_a_matters_internal_id_instead_of_its_public_id(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->tenant['matter'];

        $response = $this->getJson("/api/v1/matters/{$matter->id}");

        $response->assertStatus(404);
    }

    public function test_show_rejects_a_matter_belonging_to_a_different_provider(): void
    {
        $otherTenant = ProviderTenantScenario::make('matters-controller-other');
        Sanctum::actingAs($otherTenant['owner']);

        $response = $this->getJson("/api/v1/matters/{$this->tenant['matter']->public_id}");

        $response->assertStatus(403);
    }

    public function test_show_requires_authentication(): void
    {
        $response = $this->getJson("/api/v1/matters/{$this->tenant['matter']->public_id}");

        $response->assertStatus(401);
    }

    /**
     * `?client_id=` backs the Client Detail page's Matters tab (see
     * .claude/rules/client.md) — the same paginated `/matters` listing,
     * additionally narrowed to one client. It must never surface another
     * of the tenant's own clients' matters, even though both belong to the
     * same `provider_id`.
     */
    public function test_index_can_be_filtered_to_one_clients_own_matters(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson("/api/v1/matters?client_id={$this->tenant['client']->id}");

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertContains($this->tenant['matter']->id, $ids);
        $this->assertNotContains($this->tenant['otherMatter']->id, $ids);
    }

    /**
     * `?sort=name&direction=` backs the "Sort" filter chip on
     * /dashboard/matters — see .claude/rules/matter.md, "Sort".
     */
    public function test_index_can_be_sorted_by_name(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        \PactTrackSDK\SharedResources\Modules\Matter\Models\Matter::query()
            ->whereKey($this->tenant['matter']->id)
            ->update(['name' => 'Zeta Matter']);
        \PactTrackSDK\SharedResources\Modules\Matter\Models\Matter::query()
            ->whereKey($this->tenant['otherMatter']->id)
            ->update(['name' => 'Alpha Matter']);

        $response = $this->getJson('/api/v1/matters?sort=name&direction=asc');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertSame(['Alpha Matter', 'Zeta Matter'], $names);
    }

    /**
     * An unrecognised sort key must never reach raw SQL — it's dropped by
     * `MattersListData::fromRequest()`'s allow-list, so the request behaves
     * as if no sort was requested at all rather than erroring.
     */
    public function test_an_unknown_sort_key_is_ignored_rather_than_erroring(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/matters?sort=' . urlencode('id; DROP TABLE matters'));

        $response->assertOk();
    }

    /**
     * Resolved bug, 2026-09-16 — reassigning a matter's client via
     * PATCH /matters/{public_id} (the Edit Matter modal's Client field)
     * previously left every document filed under that matter reporting the
     * OLD client indefinitely (DocumentResource.client_id/client_name), and
     * fed that stale client into the DocuSign Send flow
     * (PrepareEnvelopeForSignature reads Document.client_id directly). See
     * .claude/rules/matter.md, "Matter Type and Edit Matter", and
     * .claude/rules/document.md, "Documents on this matter".
     */
    public function test_reassigning_a_matters_client_cascades_onto_its_documents(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->tenant['matter'];
        $document = $this->tenant['document'];
        $newClient = $this->tenant['otherClient'];

        $this->assertNotSame($newClient->id, $document->client_id);

        $response = $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'client_id' => $newClient->id,
        ]);

        $response->assertOk()->assertJsonPath('data.client.id', $newClient->id);

        $this->assertSame($newClient->id, $document->fresh()->client_id);
    }

    /**
     * The cascade must never leak beyond the matter being edited — a
     * document filed under a different matter of the same provider keeps
     * its own client untouched.
     */
    public function test_reassigning_a_matters_client_does_not_touch_documents_on_another_matter(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->tenant['matter'];
        $newClient = $this->tenant['otherClient'];
        $unrelatedDocument = $this->tenant['otherDocument'];
        $unrelatedClientId = $unrelatedDocument->client_id;

        $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'client_id' => $newClient->id,
        ])->assertOk();

        $this->assertSame($unrelatedClientId, $unrelatedDocument->fresh()->client_id);
    }

    /**
     * An already-created Envelope must keep referring to whoever it was
     * actually sent to — the cascade only corrects Document rows, never
     * Envelope/Signer snapshots. See .claude/rules/matter.md.
     */
    public function test_reassigning_a_matters_client_does_not_touch_an_existing_envelope(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->tenant['matter'];
        $envelope = $this->tenant['envelope'];
        $originalEnvelopeClientId = $envelope->client_id;
        $newClient = $this->tenant['otherClient'];

        $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'client_id' => $newClient->id,
        ])->assertOk();

        $this->assertSame($originalEnvelopeClientId, $envelope->fresh()->client_id);
    }

    /**
     * A no-op PATCH (client unchanged) must not issue the cascade update at
     * all — asserted indirectly via a same-value PATCH leaving the
     * document's `updated_at` untouched.
     */
    public function test_a_matter_update_that_does_not_change_the_client_leaves_documents_untouched(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->tenant['matter'];
        $document = $this->tenant['document'];
        $originalUpdatedAt = $document->updated_at;

        $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'client_id' => $this->tenant['client']->id,
            'name' => $matter->name,
        ])->assertOk();

        $this->assertTrue($originalUpdatedAt->eq($document->fresh()->updated_at));
    }
}
