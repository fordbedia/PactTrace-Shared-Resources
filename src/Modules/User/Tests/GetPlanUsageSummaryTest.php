<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use Laravel\Sanctum\Sanctum;
use Laravel\Sanctum\SanctumServiceProvider;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\GetPlanUsageSummary;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Extras\LoadsModuleApiRoutes;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * GetPlanUsageSummary — the read model behind every plan-gated action and
 * `GET /api/v1/plan-usage`. Run against the real bound port implementations
 * (not fakes): the numbers being *live* is the whole point, same reasoning as
 * DocumentStorageUsageServiceTest. The HTTP test at the bottom registers
 * SanctumServiceProvider and authenticates with Sanctum::actingAs() — see the
 * testing-sanctum-guard memo / EnvelopeDetailControllerTest for why that's
 * needed for a real `auth:sanctum` route.
 */
class GetPlanUsageSummaryTest extends BaseTest
{
    use LoadsModuleApiRoutes;

    private TestScenarioCollection $tenant;

    private TestScenarioCollection $otherTenant;

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), SanctumServiceProvider::class];
    }

    protected function moduleApiRoutes(): array
    {
        return [__DIR__.'/../routes/api.php'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('usage-summary-a');
        $this->otherTenant = ProviderTenantScenario::make('usage-summary-b');

        // The scenario seeds a document, an envelope and two clients of its
        // own with random-ish state — start every figure at a known zero so
        // this test is only about what it explicitly seeds.
        Document::query()->delete();
        Envelope::query()->delete();
        Client::query()->update(['status' => 'invited']);
    }

    public function test_it_counts_only_this_tenants_active_clients(): void
    {
        Client::factory()->count(2)->create(['provider_id' => $this->tenant['provider']->id, 'status' => 'active']);
        Client::factory()->create(['provider_id' => $this->tenant['provider']->id, 'status' => 'invited']);
        Client::factory()->create(['provider_id' => $this->otherTenant['provider']->id, 'status' => 'active']);

        $usage = app(GetPlanUsageSummary::class)->handle($this->tenant['provider']->id);

        $this->assertSame(2, $usage->activeClientCount);
    }

    public function test_it_counts_admin_and_staff_seats_but_not_the_owner(): void
    {
        // ProviderTenantScenario gives this tenant an owner + one staff
        // member. The owner is NOT a seat (policy, Ed 2026-09-06), so only
        // the staff member counts.
        $usage = app(GetPlanUsageSummary::class)->handle($this->tenant['provider']->id);

        $this->assertSame(1, $usage->activeStaffCount);
        $this->assertSame(0, $usage->activeAdminCount);
        $this->assertSame(1, $usage->activeStaffRoleCount);
    }

    public function test_an_admin_counts_as_a_seat_and_the_admin_staff_split_sums_to_the_total(): void
    {
        $admin = User::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'status' => 'active',
        ]);
        $admin->assignRole(Role::Admin->value);

        $usage = app(GetPlanUsageSummary::class)->handle($this->tenant['provider']->id);

        $this->assertSame(2, $usage->activeStaffCount);
        $this->assertSame(1, $usage->activeAdminCount);
        $this->assertSame(1, $usage->activeStaffRoleCount);
        $this->assertSame(
            $usage->activeStaffCount,
            $usage->activeAdminCount + $usage->activeStaffRoleCount,
        );
    }

    public function test_a_deactivated_staff_member_does_not_count_toward_seats(): void
    {
        // `status` is not mass-assignable on User (see its #[Fillable]) — force it.
        $this->tenant['staff']->forceFill(['status' => 'deactivated'])->save();

        $usage = app(GetPlanUsageSummary::class)->handle($this->tenant['provider']->id);

        // The owner never counted, and the one staff member is now inactive.
        $this->assertSame(0, $usage->activeStaffCount);
    }

    public function test_a_client_role_user_never_counts_as_a_seat(): void
    {
        // The scenario's clientUser (Role::Client) is already active and
        // provider_id-scoped to this tenant — if the seat count ever grouped
        // by provider_id alone instead of the Admin/Staff role list, this
        // would wrongly count them.
        $usage = app(GetPlanUsageSummary::class)->handle($this->tenant['provider']->id);

        $this->assertSame(1, $usage->activeStaffCount);
        $this->assertNotContains(Role::Client, Role::providerSide());
    }

    public function test_storage_used_comes_from_the_cached_provider_total(): void
    {
        // Storage is no longer a live SUM here — GetPlanUsageSummary reads the
        // cached `providers.storage_used_bytes` column, which
        // ProviderStorageLedger maintains at write time and `storage:reconcile`
        // corrects nightly (spanning documents AND message attachments).
        $this->tenant['provider']->forceFill(['storage_used_bytes' => 350])->save();
        $this->otherTenant['provider']->forceFill(['storage_used_bytes' => 999])->save();

        $usage = app(GetPlanUsageSummary::class)->handle($this->tenant['provider']->id);

        $this->assertSame(350, $usage->storageUsedBytes);
    }

    public function test_it_counts_non_draft_envelopes_created_this_month_only(): void
    {
        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'status' => EnvelopeStatus::Sent->value,
            'created_at' => now(),
        ]);
        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'status' => EnvelopeStatus::Completed->value,
            'created_at' => now(),
        ]);
        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'status' => EnvelopeStatus::Draft->value,
            'created_at' => now(),
        ]);
        Envelope::factory()->create([
            'provider_id' => $this->tenant['provider']->id,
            'status' => EnvelopeStatus::Sent->value,
            'created_at' => now()->subMonthNoOverflow()->endOfMonth(),
        ]);

        $usage = app(GetPlanUsageSummary::class)->handle($this->tenant['provider']->id);

        $this->assertSame(2, $usage->envelopesSentThisCycle);
    }

    public function test_plan_usage_endpoint_returns_usage_and_limits(): void
    {
        $this->tenant['provider']->update(['plan' => 'starter']);
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/plan-usage');

        $response->assertOk()
            ->assertJsonStructure([
                'usage' => [
                    'active_client_count', 'active_staff_count', 'admin_count', 'staff_count',
                    'storage_used_bytes', 'envelopes_sent_this_cycle',
                ],
                'limits' => ['plan', 'max_seats', 'max_active_clients', 'storage_limit_bytes'],
                'downgrade_available',
            ])
            ->assertJsonPath('limits.plan', 'starter')
            // Owner excluded — the scenario's lone staff member is the only seat.
            ->assertJsonPath('usage.active_staff_count', 1)
            ->assertJsonPath('usage.admin_count', 0)
            ->assertJsonPath('usage.staff_count', 1)
            // Starter has no tier below it — nothing to downgrade to.
            ->assertJsonPath('downgrade_available', false);
    }

    public function test_downgrade_available_is_true_for_a_firm_tenant_with_room_and_false_when_over_every_lower_tier(): void
    {
        $this->tenant['provider']->update(['plan' => 'firm']);
        Sanctum::actingAs($this->tenant['owner']);

        // Modest usage — Professional and Starter both fit.
        $this->getJson('/api/v1/plan-usage')
            ->assertOk()
            ->assertJsonPath('downgrade_available', true);

        // 3 extra staff (4 seats total) — fits Firm (5), busts Professional
        // and Starter (1 each). No lower tier is reachable.
        User::factory()->count(3)->create([
            'provider_id' => $this->tenant['provider']->id,
            'status' => 'active',
        ])->each(fn (User $u) => $u->assignRole(Role::Staff->value));

        $this->getJson('/api/v1/plan-usage')
            ->assertOk()
            ->assertJsonPath('downgrade_available', false);
    }
}
