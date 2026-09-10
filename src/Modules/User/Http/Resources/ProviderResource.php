<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use PactTrackSDK\SharedResources\Modules\User\Domain\Ports\ProviderLogoStorage;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Models\Provider;

/**
 * The tenant a user belongs to, as the SPA sees it.
 *
 * Nested inside UserResource so a signed-in browser gets its branding
 * (logo/colours), portal address and plan in the same round trip that answers
 * "who am I" — the dashboard shell needs all of it before it can render.
 *
 * An allow-list for the same reason UserResource is one: `owner_user_id` and
 * anything billing-related added to `providers` later should not start
 * appearing in an authenticated user's payload by accident.
 *
 * @mixin Provider
 */
class ProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'subdomain' => $this->subdomain,
            'custom_domain' => $this->custom_domain,
            'logo_path' => $this->logo_path,
            // Public URL of the uploaded portal logo, or null (the portal
            // renders the PactTrack mark then). Same Infrastructure-concern
            // split as UserResource.avatar_url — `url()` is pure string
            // building for the public disk, so this stays query-free.
            'logo_url' => $this->logo_path !== null
                ? app(ProviderLogoStorage::class)->url($this->logo_path)
                : null,
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'email_sender_name' => $this->email_sender_name,
            'email_reply_to' => $this->email_reply_to,
            'email_powered_by_footer' => (bool) $this->email_powered_by_footer,
            'plan' => $this->plan,
            // Everything this tenant's plan allows — seats, quotas, feature
            // flags — resolved once from PlanInfo so the SPA reads flags
            // (`capabilities.allows_custom_branding`) instead of re-deriving
            // them from the plan string. See .claude/rules/plan.md.
            'capabilities' => (Plan::tryFrom((string) $this->plan) ?? Plan::default())->info()->toArray(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            // Derived rather than stored: the SPA should not have to re-implement
            // "is this date in the past" to decide whether to show a trial banner.
            'on_trial' => $this->trial_ends_at !== null && $this->trial_ends_at->isFuture(),
            // The authoritative billing state (trialing/active/past_due/canceled/
            // expired) — see Models\Subscription and .claude/rules/user.md. The
            // frontend's trial-gate modal (GatedModal/useTrialGate) keys off this,
            // not `on_trial`: `on_trial` only reflects the cached trial_ends_at
            // date, not whether a Stripe subscription has since made it moot.
            'subscription_status' => $this->whenLoaded('subscription', fn () => $this->subscription?->status),
            // Whether this tenant has ever completed a Stripe Checkout
            // session — the frontend's one signal for "route a plan switch
            // through Checkout (Part 1) vs. the change-plan endpoint (Part 4)".
            // A provider still on RegisterProvider's card-less trial has
            // none yet. See .claude/rules/plan.md, "Stripe status".
            'has_stripe_subscription' => $this->whenLoaded(
                'subscription',
                fn () => $this->subscription?->stripe_subscription_id !== null,
            ),
            // The current Stripe billing-period end — what /dashboard/billing's
            // Current Plan card renders as "Renews …". Null while the tenant is
            // still on RegisterProvider's card-less trial (no period yet); the
            // SPA hides the "Renews" line in that case. Same whenLoaded guard
            // as the two subscription-derived fields above.
            'current_period_ends_at' => $this->whenLoaded(
                'subscription',
                fn () => $this->subscription?->current_period_ends_at?->toIso8601String(),
            ),
            // A downgrade scheduled in the Stripe Customer Portal, not yet in
            // effect — Stripe defers it to the period end. Null = no pending
            // change. PactTrack already enforces this (lower) plan's limits;
            // /dashboard/billing shows a "Downgrading to X on <date>" banner.
            // See .claude/rules/plan.md, "Pending downgrade".
            'pending_plan' => $this->whenLoaded(
                'subscription',
                fn () => $this->subscription?->pending_plan,
            ),
            'pending_plan_effective_at' => $this->whenLoaded(
                'subscription',
                fn () => $this->subscription?->pending_plan_effective_at?->toIso8601String(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
