<?php

namespace PactTrackSDK\SharedResources\Modules\User\Console\Commands;

use Illuminate\Console\Command;
use PactTrackSDK\SharedResources\Modules\User\Application\Services\ResolvePortalConfiguration;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingPortalConfigurator;

/**
 * Thin console adapter — translates the CLI invocation into one call against
 * {@see BillingPortalConfigurator} and prints the resulting `bpc_...` id. No
 * business rules here.
 *
 * One-off setup, NOT scheduled: run it once per Stripe account (and again
 * only if the permissive configuration's non-plan features change) to stand
 * up the downgrade-locked Portal configuration that
 * {@see ResolvePortalConfiguration}
 * swaps to. See .claude/rules/plan.md, "Portal configuration swap".
 *
 *   php artisan stripe:sync-portal-configs
 *   php artisan stripe:sync-portal-configs --from=bpc_xxx --update=bpc_yyy
 */
class SyncStripePortalConfigurations extends Command
{
    protected $signature = 'stripe:sync-portal-configs
        {--from= : Source (permissive) configuration id; defaults to services.stripe.billing_portal_configuration_id}
        {--update= : Existing restricted configuration id to update in place instead of creating a new one; defaults to services.stripe.billing_portal_configuration_id_restricted}';

    protected $description = 'Create/update the downgrade-locked Stripe Billing Portal configuration from the permissive one';

    public function handle(BillingPortalConfigurator $configurator): int
    {
        $source = $this->option('from')
            ?: config('services.stripe.billing_portal_configuration_id');

        if (empty($source)) {
            $this->error('No source configuration id. Set STRIPE_BILLING_PORTAL_CONFIGURATION_ID or pass --from=bpc_...');

            return self::FAILURE;
        }

        $existing = $this->option('update')
            ?: config('services.stripe.billing_portal_configuration_id_restricted')
            ?: null;

        $restrictedId = $configurator->syncRestrictedConfiguration((string) $source, $existing !== null ? (string) $existing : null);

        $verb = $existing !== null ? 'Updated' : 'Created';
        $this->info("{$verb} downgrade-locked portal configuration: {$restrictedId}");
        $this->line('Set STRIPE_BILLING_PORTAL_CONFIGURATION_ID_RESTRICTED to this value, then recreate the backend container.');

        return self::SUCCESS;
    }
}
