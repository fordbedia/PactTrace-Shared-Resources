<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Matter\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Matter-level assigned staff — see .claude/rules/matter.md,
 * "Matter-level assigned staff".
 *
 * Sanctum harness reasoning identical to MattersControllerTest: the
 * `/matters` routes sit behind real `auth:sanctum` and BaseTest only wires
 * the `web` guard.
 */
class MatterAssignedStaffTest extends BaseTest
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

        $this->tenant = ProviderTenantScenario::make('matter-assigned-staff');
    }

    private function staffUser(string $email): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'provider_id' => $this->tenant['provider']->id,
        ]);
        $user->assignRole(Role::Staff->value);

        return $user;
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

    /* ── create ──────────────────────────────────────────────────────── */

    public function test_creating_a_matter_with_a_same_provider_assigned_staff_persists_it(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->postJson('/api/v1/matters', $this->newMatterPayload([
            'assigned_staff_id' => $this->tenant['staff']->id,
        ]));

        $response->assertSuccessful();

        $this->assertDatabaseHas('matters', [
            'name' => 'Estate Plan',
            'client_id' => $this->tenant['client']->id,
            'assigned_staff_id' => $this->tenant['staff']->id,
        ]);
    }

    public function test_creating_a_matter_without_assigned_staff_leaves_the_column_null(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/matters', $this->newMatterPayload())
            ->assertSuccessful();

        $this->assertDatabaseHas('matters', [
            'name' => 'Estate Plan',
            'assigned_staff_id' => null,
        ]);
    }

    public function test_creating_a_matter_with_another_providers_user_is_rejected(): void
    {
        $other = ProviderTenantScenario::make('matter-assigned-staff-other');

        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/matters', $this->newMatterPayload([
            'assigned_staff_id' => $other['staff']->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('assigned_staff_id');

        $this->assertDatabaseMissing('matters', ['name' => 'Estate Plan']);
    }

    public function test_a_client_role_user_cannot_be_assigned(): void
    {
        Sanctum::actingAs($this->tenant['owner']);

        $this->postJson('/api/v1/matters', $this->newMatterPayload([
            'assigned_staff_id' => $this->tenant['clientUser']->id,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('assigned_staff_id');
    }

    /* ── update / reassignment ───────────────────────────────────────── */

    public function test_a_staff_member_can_reassign_a_matter_to_another_staff_or_themselves(): void
    {
        // A third party: not the owner, not the matter's current assignee.
        $actingStaff = $this->staffUser('reassigner@pacttrack.test');
        $targetStaff = $this->staffUser('covering@pacttrack.test');

        $matter = $this->tenant['matter'];
        $matter->forceFill(['assigned_staff_id' => $this->tenant['staff']->id])->save();

        Sanctum::actingAs($actingStaff);

        // Reassign to a different staffer…
        $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'assigned_staff_id' => $targetStaff->id,
        ])->assertSuccessful();

        $this->assertSame($targetStaff->id, $matter->fresh()->assigned_staff_id);

        // …and to themselves.
        $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'assigned_staff_id' => $actingStaff->id,
        ])->assertSuccessful();

        $this->assertSame($actingStaff->id, $matter->fresh()->assigned_staff_id);
    }

    public function test_reassigning_to_null_clears_the_assignment(): void
    {
        $matter = $this->tenant['matter'];
        $matter->forceFill(['assigned_staff_id' => $this->tenant['staff']->id])->save();

        Sanctum::actingAs($this->tenant['owner']);

        $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'assigned_staff_id' => null,
        ])->assertSuccessful();

        $this->assertNull($matter->fresh()->assigned_staff_id);
    }

    public function test_updating_with_another_providers_user_is_rejected(): void
    {
        $other = ProviderTenantScenario::make('matter-assigned-staff-upd-other');
        $matter = $this->tenant['matter'];

        Sanctum::actingAs($this->tenant['owner']);

        $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'assigned_staff_id' => $other['owner']->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('assigned_staff_id');

        $this->assertNull($matter->fresh()->assigned_staff_id);
    }

    public function test_a_partial_patch_of_only_assigned_staff_does_not_disturb_other_fields(): void
    {
        $matter = $this->tenant['matter'];
        $originalName = $matter->name;

        Sanctum::actingAs($this->tenant['owner']);

        $this->patchJson("/api/v1/matters/{$matter->public_id}", [
            'assigned_staff_id' => $this->tenant['staff']->id,
        ])->assertSuccessful();

        $fresh = $matter->fresh();
        $this->assertSame($originalName, $fresh->name);
        $this->assertSame($this->tenant['staff']->id, $fresh->assigned_staff_id);
    }

    /* ── FK behaviour on staff removal ───────────────────────────────── */

    public function test_deleting_the_assigned_staff_user_nulls_the_column_and_keeps_the_matter(): void
    {
        $staff = $this->staffUser('leaving@pacttrack.test');
        $matter = $this->tenant['matter'];
        $matter->forceFill(['assigned_staff_id' => $staff->id])->save();

        $staff->delete();

        $fresh = $matter->fresh();
        $this->assertNotNull($fresh, 'the matter row must survive the staff member being removed');
        $this->assertNull($fresh->assigned_staff_id);
    }

    /* ── assignable-staff endpoint ───────────────────────────────────── */

    public function test_assignable_staff_endpoint_lists_owner_and_staff_of_this_provider_only(): void
    {
        $other = ProviderTenantScenario::make('matter-assignable-other');
        $extraStaff = $this->staffUser('extra@pacttrack.test');

        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/matters/assignable-staff')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($this->tenant['owner']->id, $ids);
        $this->assertContains($this->tenant['staff']->id, $ids);
        $this->assertContains($extraStaff->id, $ids);
        $this->assertNotContains($this->tenant['clientUser']->id, $ids);
        $this->assertNotContains($other['owner']->id, $ids);
        $this->assertNotContains($other['staff']->id, $ids);

        // Owner is flagged and sorted first.
        $this->assertSame($this->tenant['owner']->id, $response->json('data.0.id'));
        $this->assertTrue($response->json('data.0.is_owner'));
        $this->assertArrayNotHasKey('email', $response->json('data.0'));
    }

    public function test_assignable_staff_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/matters/assignable-staff')->assertStatus(401);
    }
}
