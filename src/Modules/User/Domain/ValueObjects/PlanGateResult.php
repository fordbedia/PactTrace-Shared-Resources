<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * The outcome of {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Services\PlanPolicy}
 * evaluating one {@see GatedAction}. Carries `usage`/`limits` alongside the
 * verdict so a denial response is self-contained — the frontend never has to
 * make a second request just to render "8 / 10 clients" copy.
 *
 * `subscriptionStatus` is the raw `Subscription.status` the policy saw
 * ('trialing'/'active'/'past_due'/'canceled'/'expired'/null) — carried through
 * so the frontend can tell a `past_due` denial (card declined, still retrying)
 * from a `canceled` one (subscription ended) without inferring it from
 * `reason` alone, which only says "subscription_inactive". Additive: every
 * existing field keeps its meaning and shape.
 *
 * Framework-free by the hexagonal rule in CLAUDE.md.
 */
final class PlanGateResult
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?GateDenialReason $reason,
        public readonly ?string $message,
        public readonly PlanUsageSummary $usage,
        public readonly PlanInfo $limits,
        public readonly ?string $subscriptionStatus = null,
    ) {
    }

    public static function allowed(
        PlanUsageSummary $usage,
        PlanInfo $limits,
        ?string $subscriptionStatus = null,
    ): self {
        return new self(true, null, null, $usage, $limits, $subscriptionStatus);
    }

    public static function denied(
        GateDenialReason $reason,
        string $message,
        PlanUsageSummary $usage,
        PlanInfo $limits,
        ?string $subscriptionStatus = null,
    ): self {
        return new self(false, $reason, $message, $usage, $limits, $subscriptionStatus);
    }

    /**
     * @return array{allowed: bool, reason: string|null, message: string|null, subscription_status: string|null, usage: array<string, int>, limits: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'reason' => $this->reason?->value,
            'message' => $this->message,
            'subscription_status' => $this->subscriptionStatus,
            'usage' => $this->usage->toArray(),
            'limits' => $this->limits->toArray(),
        ];
    }
}
