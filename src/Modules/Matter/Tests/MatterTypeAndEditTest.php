<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Matter\Domain\Enums\MatterType;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * `matter_type` — see .claude/rules/matter.md, "Matter Type and Edit
 * Matter". A per-matter classification (Agreement/Letter/Contract/Other),
 * validated against the framework-free MatterType enum, and a real "Edit
 * Matter" capability reusing the existing MattersController::update() /
 * MatterPolicy::update gate — no permission change needed, since
 * Permission::MatterUpdate is already granted to the whole `staff` role.
 */
class MatterTypeAndEditTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('matter-type-edit');
    }

    private function newMatterPayload(array $overrides = []): array
    {
        return array_merge([
            'id' => null,
            'client_id' => $this->tenant['client']->id,
            'name' => 'Estate Plan',
            'description' => null,
            'status' => 'active',
            'start_date' => null,
            'due_date' => null,
        ], $overrides);
    }

    public function test_matter_type_persists_on_create(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/matters', $this->newMatterPayload([
            'matter_type' => MatterType::Contract->value,
        ]))->assertSuccessful();

        $this->assertDatabaseHas('matters', [
            'name' => 'Estate Plan',
            'matter_type' => MatterType::Contract->value,
        ]);
    }

    public function test_matter_type_accepts_null(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/matters', $this->newMatterPayload())
            ->assertSuccessful();

        $this->assertDatabaseHas('matters', [
            'name' => 'Estate Plan',
            'matter_type' => null,
        ]);
    }

    public function test_an_invalid_matter_type_is_rejected(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/matters', $this->newMatterPayload([
            'matter_type' => 'not-a-real-type',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('matter_type');
    }

    public function test_matter_type_persists_on_update(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->tenant['matter'];

        $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'matter_type' => MatterType::Letter->value,
        ])->assertSuccessful();

        $this->assertSame(MatterType::Letter, $matter->fresh()->matter_type);
    }

    /**
     * "admin and provider can edit matter and matter type" — Permission::
     * MatterUpdate is already granted to the whole `staff` role (not just
     * Owner/Admin), so a plain staff member — not the matter's assignee, not
     * the owner — can already edit every field through this endpoint. No
     * gate change was needed; this proves the existing permission covers it.
     */
    public function test_a_staff_member_can_edit_a_matters_full_details(): void
    {
        $staff = User::factory()->create([
            'email' => 'editor@pacttrack.test',
            'provider_id' => $this->tenant['provider']->id,
        ]);
        $staff->assignRole(Role::Staff->value);

        Sanctum::actingAs($staff);

        $matter = $this->tenant['matter'];

        $response = $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'name' => 'Renamed by staff',
            'description' => 'Updated description',
            'status' => 'on_hold',
            'matter_type' => MatterType::Agreement->value,
            'start_date' => '2026-01-01',
            'due_date' => '2026-06-01',
        ]);

        $response->assertSuccessful();

        $fresh = $matter->fresh();
        $this->assertSame('Renamed by staff', $fresh->name);
        $this->assertSame('Updated description', $fresh->description);
        $this->assertSame('on_hold', $fresh->status);
        $this->assertSame(MatterType::Agreement, $fresh->matter_type);
        $this->assertSame('2026-01-01', $fresh->start_date->toDateString());
        $this->assertSame('2026-06-01', $fresh->due_date->toDateString());
    }

    /**
     * The Edit Matter modal's Client field — see .claude/rules/matter.md,
     * "Edit Matter". `otherClient` is a second client of the SAME provider
     * (see ProviderTenantScenario's own docblock) — exactly the case a
     * reassignment picker needs to allow.
     */
    public function test_client_can_be_reassigned_on_update(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $matter = $this->tenant['matter'];
        $otherClient = $this->tenant['otherClient'];

        $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'client_id' => $otherClient->id,
        ])->assertSuccessful()
            ->assertJsonPath('data.client_id', $otherClient->id)
            ->assertJsonPath('data.client.id', $otherClient->id);

        $this->assertSame($otherClient->id, $matter->fresh()->client_id);
    }

    public function test_reassigning_to_another_tenants_client_is_rejected(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $foreignTenant = ProviderTenantScenario::make('matter-type-edit-foreign');
        $matter = $this->tenant['matter'];
        $originalClientId = $matter->client_id;

        $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'client_id' => $foreignTenant['client']->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors('client_id');

        $this->assertSame($originalClientId, $matter->fresh()->client_id);
    }

    public function test_a_client_role_user_cannot_edit_a_matter(): void
    {
        Sanctum::actingAs($this->tenant['clientUser']);

        $matter = $this->tenant['matter'];

        $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'name' => 'Should not persist',
        ])->assertStatus(403);

        $this->assertNotSame('Should not persist', $matter->fresh()->name);
    }
}
