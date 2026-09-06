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
        return [__DIR__ . '/../routes/api.php'];
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

    public function test_it_counts_active_provider_side_staff_including_the_owner(): void
    {
        // ProviderTenantScenario already gives this tenant an owner + a
        // staff member — both active, both provider-side.
        $usage = app(GetPlanUsageSummary::class)->handle($this->tenant['provider']->id);

        $this->assertSame(2, $usage->activeStaffCount);
    }

    public function test_a_deactivated_staff_member_does_not_count_toward_seats(): void
    {
        $this->tenant['staff']->update(['status' => 'deactivated']);

        $usage = app(GetPlanUsageSummary::class)->handle($this->tenant['provider']->id);

        $this->assertSame(1, $usage->activeStaffCount);
    }

    public function test_a_client_role_user_never_counts_as_a_seat(): void
    {
        // The scenario's clientUser (Role::Client) is already active and
        // provider_id-scoped to this tenant — if the seat count ever grouped
        // by provider_id alone instead of the providerSide role list, this
        // would wrongly count them.
        $usage = app(GetPlanUsageSummary::class)->handle($this->tenant['provider']->id);

        $this->assertSame(2, $usage->activeStaffCount);
        $this->assertNotContains(Role::Client, Role::providerSide());
    }

    public function test_it_sums_document_sizes_for_storage(): void
    {
        Document::factory()->create(['provider_id' => $this->tenant['provider']->id, 'size' => 100]);
        Document::factory()->create(['provider_id' => $this->tenant['provider']->id, 'size' => 250]);
        Document::factory()->create(['provider_id' => $this->otherTenant['provider']->id, 'size' => 999]);

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

        $this->assertSame(2, $usage->envelopesSentThisMonth);
    }

    public function test_plan_usage_endpoint_returns_usage_and_limits(): void
    {
        $this->tenant['provider']->update(['plan' => 'starter']);
        Sanctum::actingAs($this->tenant['owner']);

        $response = $this->getJson('/api/v1/plan-usage');

        $response->assertOk()
            ->assertJsonStructure([
                'usage' => ['active_client_count', 'active_staff_count', 'storage_used_bytes', 'envelopes_sent_this_month'],
                'limits' => ['plan', 'max_seats', 'max_active_clients', 'storage_limit_bytes'],
            ])
            ->assertJsonPath('limits.plan', 'starter')
            ->assertJsonPath('usage.active_staff_count', 2);
    }
}
