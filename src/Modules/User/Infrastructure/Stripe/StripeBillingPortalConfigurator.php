<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe;

use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingPortalConfigurator;
use Stripe\StripeClient;

/**
 * The real adapter for {@see BillingPortalConfigurator}. Reads the permissive
 * Portal configuration straight from Stripe, copies its non-subscription
 * features, and writes a sibling configuration with `subscription_update`
 * turned off — so a session opened with it shows no plan-change UI at all
 * (Stripe's hosted Portal has no native "upgrade-only" toggle; disabling the
 * feature is how "no downgrade button" is expressed). Every other feature
 * (invoice history, payment-method update, cancellation, customer update) is
 * carried over verbatim, so the two configurations can't drift.
 */
final class StripeBillingPortalConfigurator implements BillingPortalConfigurator
{
    public function __construct(
        private readonly StripeClient $client,
    ) {}

    public function syncRestrictedConfiguration(
        string $sourceConfigurationId,
        ?string $existingRestrictedId = null,
    ): string {
        $source = $this->client->billingPortal->configurations->retrieve($sourceConfigurationId, []);

        $features = $this->carriedOverFeatures($source->features ?? null);
        $features['subscription_update'] = ['enabled' => false];

        $params = [
            'business_profile' => $this->businessProfile($source->business_profile ?? null),
            'features' => $features,
            'metadata' => [
                'pacttrack_role' => 'downgrade_locked',
                'pacttrack_source' => $sourceConfigurationId,
            ],
        ];

        if ($existingRestrictedId !== null && $existingRestrictedId !== '') {
            $updated = $this->client->billingPortal->configurations->update($existingRestrictedId, $params);

            return (string) $updated->id;
        }

        $created = $this->client->billingPortal->configurations->create($params);

        return (string) $created->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function carriedOverFeatures(mixed $sourceFeatures): array
    {
        $features = [];

        foreach (['customer_update', 'invoice_history', 'payment_method_update', 'subscription_cancel'] as $key) {
            $feature = is_object($sourceFeatures) ? ($sourceFeatures->{$key} ?? null) : null;

            if ($feature === null) {
                continue;
            }

            $features[$key] = $this->toArray($feature);
        }

        return $features;
    }

    /**
     * @return array<string, mixed>
     */
    private function businessProfile(mixed $sourceProfile): array
    {
        if (! is_object($sourceProfile)) {
            return [];
        }

        return array_filter([
            'headline' => $sourceProfile->headline ?? null,
            'privacy_policy_url' => $sourceProfile->privacy_policy_url ?? null,
            'terms_of_service_url' => $sourceProfile->terms_of_service_url ?? null,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $stripeObject): array
    {
        if (is_array($stripeObject)) {
            return $stripeObject;
        }

        if (is_object($stripeObject) && method_exists($stripeObject, 'toArray')) {
            return $stripeObject->toArray();
        }

        return (array) $stripeObject;
    }
}
