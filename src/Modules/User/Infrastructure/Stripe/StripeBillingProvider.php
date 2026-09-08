<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Infrastructure\Stripe;

use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\InvalidStripeWebhookSignatureException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSession;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSessionRequest;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\CheckoutSessionStatus;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\StripeWebhookEventData;
use Stripe\Exception\ApiErrorException;
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
            // No `trial_period_days`: every provider already had their 14-day
            // card-less trial at sign-up, so Checkout is always an immediate
            // charge — Stripe's page shows "Subscribe" / amount due today, not
            // "N days free". See CreateCheckoutSession.
            'subscription_data' => [
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

    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionStatus
    {
        try {
            $session = $this->client->checkout->sessions->retrieve($sessionId, [
                'expand' => ['subscription', 'subscription.default_payment_method'],
            ]);
        } catch (ApiErrorException $e) {
            // A bad/unknown/expired session id — not an error condition here,
            // it's exactly the "we couldn't confirm this payment" outcome.
            return CheckoutSessionStatus::notFound();
        }

        $subscription = $session->subscription; // expanded object, a string id, or null
        $subscriptionObject = is_object($subscription) ? $subscription->toArray() : [];

        $customer = $session->customer; // not expanded — a string id or null
        $customerId = is_string($customer) && $customer !== '' ? $customer : null;

        [$cardBrand, $cardLast4] = $this->cardFrom($subscription);

        return new CheckoutSessionStatus(
            found: true,
            paymentStatus: (string) ($session->payment_status ?? ''),
            customerId: $customerId,
            clientReferenceId: $session->client_reference_id !== null
                ? (string) $session->client_reference_id
                : null,
            subscriptionObject: $subscriptionObject,
            amountTotalCents: $session->amount_total !== null ? (int) $session->amount_total : null,
            currency: $session->currency !== null ? (string) $session->currency : null,
            cardBrand: $cardBrand,
            cardLast4: $cardLast4,
        );
    }

    /**
     * Best-effort card brand/last4 off the expanded
     * `subscription.default_payment_method`. Often absent on a
     * just-created subscription (the method can live on the customer's
     * invoice settings or the first invoice instead) — a null pair is a
     * normal outcome, the success screen just omits the "Billed to" line.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function cardFrom(mixed $subscription): array
    {
        if (! is_object($subscription)) {
            return [null, null];
        }

        $method = $subscription->default_payment_method ?? null;
        $card = is_object($method) ? ($method->card ?? null) : null;

        if (! is_object($card)) {
            return [null, null];
        }

        return [
            isset($card->brand) ? (string) $card->brand : null,
            isset($card->last4) ? (string) $card->last4 : null,
        ];
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
