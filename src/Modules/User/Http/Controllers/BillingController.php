<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\ChangeSubscriptionPlan;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\CreateBillingPortalSession;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\CreateCheckoutSession;
use PactTrackSDK\SharedResources\Modules\User\Application\UseCases\Billing\ReconcileCheckoutSession;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\NoStripeCustomerException;
use PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions\PlanChangeBlockedException;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\BillingInterval;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\ChangePlanRequest;
use PactTrackSDK\SharedResources\Modules\User\Http\Requests\CheckoutRequest;
use PactTrackSDK\SharedResources\Modules\User\Models\User;

/**
 * `/dashboard/billing`'s three real endpoints — Checkout, the Customer
 * Portal, and the plan-change pre-flight. Every action is gated by
 * `ProviderPolicy::manageBilling` (`provider.manage-billing`, owner-only —
 * only `Role::Owner` holds the whole permission catalogue, see
 * .claude/rules/user.md), same "gate, then act" shape as BrandingController.
 * See .claude/rules/plan.md, "Stripe status: not wired yet".
 */
class BillingController extends Controller
{
    public function __construct(
        private readonly CreateCheckoutSession $createCheckoutSession,
        private readonly CreateBillingPortalSession $createPortalSession,
        private readonly ChangeSubscriptionPlan $changePlan,
        private readonly ReconcileCheckoutSession $reconcileCheckoutSession,
    ) {
    }

    /**
     * POST /api/v1/billing/checkout
     */
    public function checkout(CheckoutRequest $request): JsonResponse
    {
        $user = $this->gate($request);

        $plan = Plan::from($request->string('plan')->toString());
        $interval = BillingInterval::from($request->string('billing_interval', BillingInterval::Monthly->value)->toString());

        $session = $this->createCheckoutSession->handle($user, $plan, $interval);

        return response()->json(['checkout_url' => $session->url]);
    }

    /**
     * GET /api/v1/billing/portal-session
     */
    public function portalSession(Request $request): JsonResponse
    {
        $user = $this->gate($request);

        try {
            $url = $this->createPortalSession->handle($user);
        } catch (NoStripeCustomerException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['portal_url' => $url]);
    }

    /**
     * GET /api/v1/billing/checkout-session/{session}/status
     *
     * The `/checkout/success` landing page's reconciliation call. `{session}`
     * is a Stripe Checkout Session id (`cs_...`), not a model — no route
     * binding. Returns `status` (`confirmed` | `pending` | `failed`) plus the
     * details the success screen renders; `amount_label` / `card_label` are
     * present only when the reconciliation actually had to read Stripe.
     */
    public function checkoutSessionStatus(Request $request, string $session): JsonResponse
    {
        $user = $this->gate($request);

        $result = $this->reconcileCheckoutSession->handle((int) $user->provider_id, $session);
        $subscription = $result->subscription;
        $plan = $subscription?->plan !== null ? Plan::tryFrom((string) $subscription->plan) : null;

        return response()->json([
            'status' => $result->state,
            'plan' => $plan?->label(),
            'plan_key' => $plan?->value,
            'amount_label' => $result->amountLabel(),
            'card_label' => $result->cardLabel(),
            'renews_at' => $subscription?->current_period_ends_at?->toIso8601String(),
            'billing_email' => $user->email,
        ]);
    }

    /**
     * POST /api/v1/billing/change-plan
     */
    public function changePlan(ChangePlanRequest $request): JsonResponse
    {
        $user = $this->gate($request);
        $targetPlan = Plan::from($request->string('target_plan')->toString());

        try {
            $this->changePlan->handle($user, $targetPlan);
        } catch (PlanChangeBlockedException $e) {
            return response()->json([
                'message' => "Your current usage exceeds the {$targetPlan->label()} plan's limits.",
                ...$e->result->toArray(),
            ], 422);
        } catch (NoStripeCustomerException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Plan change requested.']);
    }

    /**
     * Gate #1 (the only gate here — billing has no plan-tier restriction of
     * its own the way branding does) and hand back the acting user.
     */
    private function gate(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        $provider = $user->provider;

        abort_if($provider === null, 403);
        Gate::forUser($user)->authorize('manageBilling', $provider);

        return $user;
    }
}
