<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe;

use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\InvalidStripeWebhookSignatureException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSession;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSessionRequest;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * The real adapter — everything that actually talks to Stripe's API lives
 * here, behind {@see BillingProvider}. Uses a `StripeClient` instance
 * (constructed with the secret key at bind time, see UserProvider::register())
 * rather than the SDK's static `Stripe::setApiKey()` + class-based calls, so
 * the key is scoped to this one object instead of mutated as process-wide
 * global state.
 */
final class StripeBillingProvider implements BillingProvider
{
    public function __construct(
        private readonly StripeClient $client,
    ) {
    }

    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $params = [
            'mode' => 'subscription',
            'line_items' => [
                ['price' => $request->priceId, 'quantity' => 1],
            ],
            'client_reference_id' => $request->providerId,
            'subscription_data' => [
                'trial_period_days' => $request->trialPeriodDays,
                'metadata' => ['provider_id' => $request->providerId],
            ],
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
        ];

        if ($request->customerId !== null) {
            $params['customer'] = $request->customerId;
        } elseif ($request->customerEmail !== null) {
            $params['customer_email'] = $request->customerEmail;
        }

        $session = $this->client->checkout->sessions->create($params);

        return new CheckoutSession(url: (string) $session->url);
    }

    public function createBillingPortalSession(string $customerId, string $returnUrl): string
    {
        $params = ['customer' => $customerId, 'return_url' => $returnUrl];

        $configurationId = config('services.stripe.billing_portal_configuration_id');
        if (! empty($configurationId)) {
            $params['configuration'] = $configurationId;
        }

        $session = $this->client->billingPortal->sessions->create($params);

        return (string) $session->url;
    }

    public function updateSubscriptionPrice(string $subscriptionId, string $newPriceId, string $prorationBehavior): void
    {
        $subscription = $this->client->subscriptions->retrieve($subscriptionId);
        $itemId = (string) $subscription->items->data[0]->id;

        $this->client->subscriptions->update($subscriptionId, [
            'items' => [
                ['id' => $itemId, 'price' => $newPriceId],
            ],
            'proration_behavior' => $prorationBehavior,
        ]);
    }

    public function constructWebhookEvent(string $payload, string $signature, string $webhookSecret): StripeWebhookEventData
    {
        try {
            $event = Webhook::constructEvent($payload, $signature, $webhookSecret);
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            throw new InvalidStripeWebhookSignatureException($e->getMessage(), previous: $e);
        }

        return new StripeWebhookEventData(
            id: (string) $event->id,
            type: (string) $event->type,
            object: $event->data->object->toArray(),
        );
    }
}
