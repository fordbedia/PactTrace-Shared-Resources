<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Tests;

use PactTraceSDK\SharedResources\Modules\Document\Application\Action\GetStorageUsageAction;
use PactTraceSDK\SharedResources\Modules\Document\Application\Port\Service\StorageUsageCalculator;
use PactTraceSDK\SharedResources\Modules\Document\Domain\Ports\StorageQuotas;
use PactTraceSDK\SharedResources\Modules\Document\Domain\ValueObjects\StorageUsage;
use PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Repositories\Eloquent\EloquentDocumentRepository;
use PactTraceSDK\SharedResources\Modules\Document\Infrastructure\Services\DocumentStorageUsageService;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTraceSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTraceSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * The calculation behind the STORAGE indicator on /dashboard/documents — a
 * live SUM over the tenant's documents against the plan's allowance.
 *
 * Run against the real Eloquent repository rather than a fake: the number
 * being *live* is the whole point of the feature, and a stubbed repository
 * would assert nothing about whether the aggregate actually reflects what was
 * uploaded. The quota side is stubbed instead, since that's config.
 */
class DocumentStorageUsageServiceTest extends BaseTest
{
    private DocumentStorageUsageService $service;

    private TestScenarioCollection $tenant;

    private TestScenarioCollection $otherTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DocumentStorageUsageService(
            new EloquentDocumentRepository(),
            new FixedStorageQuotas(1_000),
        );

        $this->tenant = ProviderTenantScenario::make('usage-a');
        $this->otherTenant = ProviderTenantScenario::make('usage-b');

        // The scenario seeds documents of random size; start from a known
        // zero so every assertion below is about what this test uploaded.
        Document::query()->delete();
    }

    public function test_it_is_the_bound_implementation_of_the_port(): void
    {
        $this->assertInstanceOf(DocumentStorageUsageService::class, app(StorageUsageCalculator::class));
    }

    public function test_usage_is_zero_for_a_tenant_with_no_documents(): void
    {
        $usage = $this->service->forProvider($this->tenant['provider']->id);

        $this->assertSame(0, $usage->usedBytes);
        $this->assertSame(0.0, $usage->percentage());
    }

    public function test_it_sums_the_tenants_documents(): void
    {
        $this->document($this->tenant, 200);
        $this->document($this->tenant, 300);

        $this->assertSame(500, $this->service->forProvider($this->tenant['provider']->id)->usedBytes);
    }

    public function test_it_reflects_a_new_upload(): void
    {
        $this->document($this->tenant, 250);
        $this->assertSame(250, $this->service->forProvider($this->tenant['provider']->id)->usedBytes);

        $this->document($this->tenant, 250);
        $this->assertSame(500, $this->service->forProvider($this->tenant['provider']->id)->usedBytes);
    }

    public function test_it_never_counts_another_tenants_documents(): void
    {
        $this->document($this->tenant, 100);
        $this->document($this->otherTenant, 900);

        $this->assertSame(100, $this->service->forProvider($this->tenant['provider']->id)->usedBytes);
        $this->assertSame(900, $this->service->forProvider($this->otherTenant['provider']->id)->usedBytes);
    }

    public function test_it_narrows_to_one_client(): void
    {
        // A client-role actor is shown their own consumption, not the whole
        // practice's — otherwise the indicator leaks how much business the
        // provider does.
        $this->document($this->tenant, 100, $this->tenant['client']->id);
        $this->document($this->tenant, 400, $this->tenant['otherClient']->id);

        $usage = $this->service->forProvider(
            $this->tenant['provider']->id,
            null,
            $this->tenant['client']->id,
        );

        $this->assertSame(100, $usage->usedBytes);
    }

    public function test_the_limit_comes_from_the_plan(): void
    {
        $service = new DocumentStorageUsageService(
            new EloquentDocumentRepository(),
            new FixedStorageQuotas(1_000, ['firm' => 9_000]),
        );

        $this->assertSame(1_000, $service->forProvider($this->tenant['provider']->id, 'starter')->limitBytes);
        $this->assertSame(9_000, $service->forProvider($this->tenant['provider']->id, 'firm')->limitBytes);
    }

    public function test_it_computes_the_percentage_used(): void
    {
        $this->document($this->tenant, 620);

        $usage = $this->service->forProvider($this->tenant['provider']->id);

        $this->assertSame(62.0, $usage->percentage());
        $this->assertSame(380, $usage->remainingBytes());
        $this->assertFalse($usage->isOverLimit());
    }

    public function test_it_reports_going_over_the_allowance(): void
    {
        // Nothing enforces the quota at upload time today, so "used > limit"
        // is a reachable state, not a hypothetical one.
        $this->document($this->tenant, 1_500);

        $usage = $this->service->forProvider($this->tenant['provider']->id);

        $this->assertTrue($usage->isOverLimit());
        $this->assertSame(100.0, $usage->percentage(), 'The bar clamps rather than overflowing its track.');
        $this->assertSame(0, $usage->remainingBytes());
    }

    public function test_the_action_derives_the_tenant_plan_and_client_from_the_actor(): void
    {
        $this->tenant['provider']->forceFill(['plan' => 'firm'])->save();

        $this->document($this->tenant, 100, $this->tenant['client']->id);
        $this->document($this->tenant, 400, $this->tenant['otherClient']->id);

        $calculator = new DocumentStorageUsageService(
            new EloquentDocumentRepository(),
            new FixedStorageQuotas(1_000, ['firm' => 9_000]),
        );
        $action = new GetStorageUsageAction($calculator);

        // Provider-side actor: whole tenant, on the provider's plan.
        $ownerUsage = $action->handle($this->tenant['owner']->fresh());
        $this->assertSame(500, $ownerUsage->usedBytes);
        $this->assertSame(9_000, $ownerUsage->limitBytes);

        // Client actor: only their own documents.
        $clientUsage = $action->handle($this->tenant['clientUser']->fresh());
        $this->assertSame(100, $clientUsage->usedBytes);
    }

    private function document(TestScenarioCollection $tenant, int $size, ?int $clientId = null): Document
    {
        return Document::factory()->create([
            'provider_id' => $tenant['provider']->id,
            'workspace_id' => $tenant['workspace']->id,
            'uploaded_by' => $tenant['owner']->id,
            'client_id' => $clientId,
            'size' => $size,
        ]);
    }
}

/**
 * A StorageQuotas stub with the allowances written in the test rather than in
 * config — ConfigStorageQuotasTest is what covers reading the real file.
 */
class FixedStorageQuotas implements StorageQuotas
{
    /**
     * @param array<string, int> $perPlan
     */
    public function __construct(
        private readonly int $default,
        private readonly array $perPlan = [],
    ) {
    }

    public function bytesForPlan(?string $plan): int
    {
        return $this->perPlan[$plan] ?? $this->default;
    }
}
