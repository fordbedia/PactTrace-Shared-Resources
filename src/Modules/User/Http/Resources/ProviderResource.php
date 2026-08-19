<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'plan' => $this->plan,
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
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
