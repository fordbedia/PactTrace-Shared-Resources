<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Tests;

use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingPortalConfigurator;
use PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe\FakeBillingPortalConfigurator;
use PactTrackSDK\SharedResources\TestCase\Migrations\BaseTest;

/**
 * `stripe:sync-portal-configs` — the one-off command that provisions the
 * downgrade-locked Portal configuration. Thin adapter, so the test only
 * checks option-defaulting and that it delegates to
 * {@see BillingPortalConfigurator} (FakeBillingPortalConfigurator here).
 */
class SyncStripePortalConfigurationsTest extends BaseTest
{
    private FakeBillingPortalConfigurator $configurator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configurator = new FakeBillingPortalConfigurator;
        $this->app->bind(BillingPortalConfigurator::class, fn () => $this->configurator);
    }

    public function test_it_fails_when_no_source_configuration_is_available(): void
    {
        config()->set('services.stripe.billing_portal_configuration_id', null);

        $this->artisan('stripe:sync-portal-configs')->assertExitCode(1);

        $this->assertSame([], $this->configurator->calls);
    }

    public function test_it_creates_from_the_configured_permissive_id_when_no_options_given(): void
    {
        config()->set('services.stripe.billing_portal_configuration_id', 'bpc_source');
        config()->set('services.stripe.billing_portal_configuration_id_restricted', null);

        $this->artisan('stripe:sync-portal-configs')
            ->expectsOutputToContain('bpc_fake_restricted')
            ->assertExitCode(0);

        $this->assertSame(
            [['source' => 'bpc_source', 'existing' => null]],
            $this->configurator->calls,
        );
    }

    public function test_options_override_config_and_trigger_an_in_place_update(): void
    {
        config()->set('services.stripe.billing_portal_configuration_id', 'bpc_source');

        $this->artisan('stripe:sync-portal-configs', [
            '--from' => 'bpc_other_source',
            '--update' => 'bpc_existing_restricted',
        ])->assertExitCode(0);

        $this->assertSame(
            [['source' => 'bpc_other_source', 'existing' => 'bpc_existing_restricted']],
            $this->configurator->calls,
        );
    }

    public function test_it_updates_in_place_when_a_restricted_id_is_already_configured(): void
    {
        config()->set('services.stripe.billing_portal_configuration_id', 'bpc_source');
        config()->set('services.stripe.billing_portal_configuration_id_restricted', 'bpc_current_restricted');

        $this->artisan('stripe:sync-portal-configs')->assertExitCode(0);

        $this->assertSame(
            [['source' => 'bpc_source', 'existing' => 'bpc_current_restricted']],
            $this->configurator->calls,
        );
    }
}
