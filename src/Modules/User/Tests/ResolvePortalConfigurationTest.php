<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\ResolvePortalConfiguration;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;
use PactTrackSDK\SharedResources\TestCase\Scenario\ProviderTenantScenario;
use PactTrackSDK\SharedResources\TestCase\Scenario\TestScenarioCollection;

/**
 * Which Stripe Portal configuration a session opens with — permissive vs.
 * downgrade-locked. Run against the real bound GetPlanUsageSummary (the
 * usage figures being live is the whole point), same as
 * GetPlanUsageSummaryTest.
 */
class ResolvePortalConfigurationTest extends BaseTest
{
    private const DEFAULT_ID = 'bpc_permissive';

    private const RESTRICTED_ID = 'bpc_restricted';

    private TestScenarioCollection $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = ProviderTenantScenario::make('resolve-portal-config');

        // Known-zero baseline — the scenario seeds a doc/envelope/clients.
        Document::query()->delete();
        Envelope::query()->delete();
        Client::query()->update(['status' => 'invited']);

        config()->set('services.stripe.billing_portal_configuration_id', self::DEFAULT_ID);
        config()->set('services.stripe.billing_portal_configuration_id_restricted', self::RESTRICTED_ID);
    }

    private function resolver(): ResolvePortalConfiguration
    {
        return app(ResolvePortalConfiguration::class);
    }

    private function owner(): User
    {
        return $this->tenant['owner']->fresh();
    }

    private function addActiveStaff(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $user = User::factory()->create([
                'provider_id' => $this->tenant['provider']->id,
                'status' => 'active',
            ]);
            $user->assignRole(Role::Staff->value);
        }
    }

    public function test_a_firm_tenant_with_room_to_downgrade_gets_the_permissive_configuration(): void
    {
        $this->tenant['provider']->update(['plan' => 'firm']);

        $this->assertSame(self::DEFAULT_ID, $this->resolver()->resolveFor($this->owner()));
    }

    public function test_a_firm_tenant_that_fits_no_lower_tier_gets_the_restricted_configuration(): void
    {
        $this->tenant['provider']->update(['plan' => 'firm']);

        // 3 extra staff + the scenario's 1 = 4 seats. Fits Firm (5) but busts
        // Professional and Starter (1 each) — no lower tier is reachable.
        $this->addActiveStaff(3);

        $this->assertSame(self::RESTRICTED_ID, $this->resolver()->resolveFor($this->owner()));
    }

    public function test_a_starter_tenant_always_gets_the_permissive_configuration(): void
    {
        $this->tenant['provider']->update(['plan' => 'starter']);
        $this->addActiveStaff(3); // irrelevant — there is no tier below Starter

        $this->assertSame(self::DEFAULT_ID, $this->resolver()->resolveFor($this->owner()));
    }

    public function test_with_no_restricted_configuration_set_it_never_swaps(): void
    {
        config()->set('services.stripe.billing_portal_configuration_id_restricted', null);
        $this->tenant['provider']->update(['plan' => 'firm']);
        $this->addActiveStaff(3); // would otherwise force the restricted id

        $this->assertSame(self::DEFAULT_ID, $this->resolver()->resolveFor($this->owner()));
    }

    public function test_with_neither_configuration_set_it_returns_null(): void
    {
        config()->set('services.stripe.billing_portal_configuration_id', null);
        config()->set('services.stripe.billing_portal_configuration_id_restricted', null);
        $this->tenant['provider']->update(['plan' => 'firm']);

        $this->assertNull($this->resolver()->resolveFor($this->owner()));
    }
}
