<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * Why {@see PlanPolicy::evaluate()} denied a {@see GatedAction} — the two
 * questions it asks, in order. The frontend's plan-guard hook renders
 * different copy for each (see .claude/rules/plan.md).
 */
enum GateDenialReason: string
{
    /** The tenant's subscription isn't 'trialing'/'active' — nothing plan-tier-specific about it. */
    case SubscriptionInactive = 'subscription_inactive';

    /** Subscription is fine; this specific action would exceed the plan's own limit. */
    case PlanLimitExceeded = 'plan_limit_exceeded';
}
