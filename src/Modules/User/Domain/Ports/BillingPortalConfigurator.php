<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Ports;

/**
 * A provisioning-only seam to Stripe's Billing Portal *Configuration* API —
 * deliberately separate from {@see BillingProvider} (runtime billing
 * operations every request may hit). Nothing in the request path calls this;
 * it exists for the `stripe:sync-portal-configs` artisan command, which
 * stands up the "downgrade-locked" Portal configuration once per Stripe
 * account. See .claude/rules/plan.md, "Portal configuration swap".
 *
 * Implemented by Infrastructure\Stripe\StripeBillingPortalConfigurator (the
 * real `stripe-php` client) and Infrastructure\Stripe\FakeBillingPortalConfigurator
 * (no network — bound in tests, same "fake over mock" convention as
 * {@see BillingProvider}).
 */
interface BillingPortalConfigurator
{
    /**
     * Create — or, when `$existingRestrictedId` is given, update in place —
     * a Billing Portal configuration that mirrors the permissive
     * configuration `$sourceConfigurationId` in every respect *except* that
     * `subscription_update` is disabled (no plan switching; cancellation and
     * payment-method updates stay on). Returns the restricted
     * configuration's `bpc_...` id.
     */
    public function syncRestrictedConfiguration(
        string $sourceConfigurationId,
        ?string $existingRestrictedId = null,
    ): string;
}
