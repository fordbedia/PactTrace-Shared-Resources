<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports\StripeWebhookEventRepository;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\CancelSubscriptionFromStripe;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\ClearPaymentFailure;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\HandleTrialWillEnd;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\LinkStripeCustomerToSubscription;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\RecordPaymentFailure;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\SyncSubscriptionFromStripe;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\InvalidStripeWebhookSignatureException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\BillingProvider;

/**
 * `POST /api/v1/stripe/webhook` — no auth middleware (Stripe can't send a
 * session cookie); `BillingProvider::constructWebhookEvent()` verifying the
 * signature is what stands in for authentication, same pattern as
 * DocusignWebhookController. Idempotency is `StripeWebhookEventRepository`'s
 * job (keyed on Stripe's own `event.id`, not a payload hash — Stripe already
 * guarantees that id is stable across redeliveries).
 *
 * Thin: dispatches each event type to exactly one
 * `Application\UseCases\Billing\*` handler. Every rejection/no-op path logs
 * before returning — same rule .claude/rules/signature.md's "Webhook
 * failures are never silent" states for the DocuSign side, so a
 * misconfigured or unrecognised delivery is never silently invisible.
 */
class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly BillingProvider $billing,
        private readonly StripeWebhookEventRepository $events,
        private readonly LinkStripeCustomerToSubscription $linkCustomer,
        private readonly SyncSubscriptionFromStripe $syncSubscription,
        private readonly CancelSubscriptionFromStripe $cancelSubscription,
        private readonly RecordPaymentFailure $recordPaymentFailure,
        private readonly ClearPaymentFailure $clearPaymentFailure,
        private readonly HandleTrialWillEnd $handleTrialWillEnd,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature');
        $webhookSecret = (string) config('services.stripe.webhook_secret');

        try {
            $event = $this->billing->constructWebhookEvent($raw, $signature, $webhookSecret);
        } catch (InvalidStripeWebhookSignatureException $e) {
            Log::warning('Stripe webhook rejected: signature verification failed.', [
                'has_signature_header' => $request->hasHeader('Stripe-Signature'),
                'content_length' => strlen($raw),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid webhook signature.'], 400);
        }

        if (! $this->events->recordIfNew($event->id, $event->type)) {
            Log::info('Stripe webhook skipped: already processed.', ['stripe_event_id' => $event->id, 'type' => $event->type]);

            return response()->json(['received' => true]);
        }

        match ($event->type) {
            'checkout.session.completed' => $this->linkCustomer->handle($event),
            'customer.subscription.created', 'customer.subscription.updated' => $this->syncSubscription->handle($event),
            'customer.subscription.deleted' => $this->cancelSubscription->handle($event),
            'invoice.payment_failed' => $this->recordPaymentFailure->handle($event),
            'invoice.paid' => $this->clearPaymentFailure->handle($event),
            'customer.subscription.trial_will_end' => $this->handleTrialWillEnd->handle($event),
            default => Log::debug('Stripe webhook ignored: no handler for this event type.', ['type' => $event->type]),
        };

        $this->events->markProcessed($event->id);

        return response()->json(['received' => true]);
    }
}
