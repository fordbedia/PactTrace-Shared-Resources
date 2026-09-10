<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Services;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\GatedAction;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\GateDenialReason;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Plan;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanGateResult;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanInfo;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanUsageSummary;

/**
 * The one place a {@see GatedAction} is allowed or denied. Behavior, not
 * data — unlike {@see Plan} (a closed set of three identity values, correctly
 * an enum) this is a decision that reads a subscription status and a live
 * usage snapshot, which a PHP enum case cannot carry. Same reasoning
 * {@see PlanInfo}'s own docblock gives for why it is a class and not a second
 * enum. Framework-free by the hexagonal rule in CLAUDE.md — no session, no
 * Eloquent, just the four inputs below.
 *
 * Two questions, always in this order (see .claude/rules/plan.md,
 * "Downgrade / over-limit policy" — the subscription check maps to
 * useTrialGate()'s existing frontend logic, so both layers fail closed the
 * same way):
 *
 *  1. Is the subscription itself usable right now ('trialing'/'active')?
 *  2. Does the plan's own limit for this specific action leave room, given
 *     current usage?
 *
 * The only constructor for a {@see PlanGateResult} outside this class is
 * {@see PlanGateResult::allowed()}/{@see PlanGateResult::denied()} — nothing
 * else should build one, and nothing outside this class should re-derive
 * "is this tenant within their plan" a second way.
 */
final class PlanPolicy
{
    /** @var list<string> */
    private const ACTIVE_SUBSCRIPTION_STATUSES = ['trialing', 'active'];

    /**
     * `$plan` is the *effective* plan — {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Services\EffectivePlan}
     * resolves it to the pending (lower) tier the moment a Portal downgrade is
     * scheduled. `$viaPendingDowngrade` / `$pendingEffectiveAtLabel` only shape
     * the denial copy so the modal can say *why* the smaller limit applies —
     * they never change the verdict.
     */
    public function evaluate(
        GatedAction $action,
        Plan $plan,
        ?string $subscriptionStatus,
        PlanUsageSummary $usage,
        bool $viaPendingDowngrade = false,
        ?string $pendingEffectiveAtLabel = null,
    ): PlanGateResult {
        $limits = $plan->info();

        if (! in_array($subscriptionStatus, self::ACTIVE_SUBSCRIPTION_STATUSES, true)) {
            return PlanGateResult::denied(
                GateDenialReason::SubscriptionInactive,
                'Your subscription is not active. Please update your billing to continue.',
                $usage,
                $limits,
                $subscriptionStatus,
            );
        }

        [$limit, $current] = $this->limitFor($action, $limits, $usage);

        if ($limit !== null && $current >= $limit) {
            return PlanGateResult::denied(
                GateDenialReason::PlanLimitExceeded,
                $viaPendingDowngrade
                    ? $this->pendingDowngradeMessage($action, $limits, $pendingEffectiveAtLabel)
                    : $this->limitMessage($action, $limits),
                $usage,
                $limits,
                $subscriptionStatus,
            );
        }

        return PlanGateResult::allowed($usage, $limits, $subscriptionStatus);
    }

    /**
     * @return array{0: int|null, 1: int} [limit (null = unlimited), current usage]
     */
    private function limitFor(GatedAction $action, PlanInfo $limits, PlanUsageSummary $usage): array
    {
        return match ($action) {
            GatedAction::UploadDocument => [$limits->storageLimitBytes, $usage->storageUsedBytes],
            GatedAction::PrepareForSignature => [$limits->maxEnvelopesPerMonth, $usage->envelopesSentThisCycle],
            GatedAction::InviteClient => [$limits->maxActiveClients, $usage->activeClientCount],
            GatedAction::InviteStaff => [$limits->maxSeats, $usage->activeStaffCount],
        };
    }

    private function limitMessage(GatedAction $action, PlanInfo $limits): string
    {
        return match ($action) {
            GatedAction::UploadDocument => "You've reached your {$limits->label} plan's {$limits->storageLimitLabel} storage limit. Upgrade to keep uploading.",
            GatedAction::PrepareForSignature => "You've reached your {$limits->label} plan's {$limits->maxEnvelopesPerMonth}-envelope-per-billing-cycle limit. Upgrade to send more for signature this cycle.",
            GatedAction::InviteClient => "You've reached your {$limits->label} plan's {$limits->maxActiveClients}-client limit. Upgrade to add more clients.",
            GatedAction::InviteStaff => "You've reached your {$limits->label} plan's {$limits->maxSeats}-seat limit. Upgrade to add more team members.",
        };
    }

    /**
     * Same denial, but worded for a tenant who is over the limit *because they
     * scheduled a downgrade in the Stripe Portal* — names the cause and both
     * fixes (cancel the downgrade, or reduce usage). See
     * {@see \PactTrackSDK\SharedResources\Modules\User\Domain\Services\EffectivePlan}.
     */
    private function pendingDowngradeMessage(GatedAction $action, PlanInfo $limits, ?string $effectiveAtLabel): string
    {
        $when = $effectiveAtLabel !== null && $effectiveAtLabel !== '' ? ", effective {$effectiveAtLabel}," : '';

        return match ($action) {
            GatedAction::UploadDocument => "Your scheduled downgrade to {$limits->label}{$when} caps storage at {$limits->storageLimitLabel}, and you've reached it. Cancel the downgrade or free up space to keep uploading.",
            GatedAction::PrepareForSignature => "Your scheduled downgrade to {$limits->label}{$when} caps e-signatures at {$limits->maxEnvelopesPerMonth} per billing cycle, and you've reached it. Cancel the downgrade to send more this cycle.",
            GatedAction::InviteClient => "Your scheduled downgrade to {$limits->label}{$when} caps active clients at {$limits->maxActiveClients}, and you've reached it. Cancel the downgrade or archive clients to add more.",
            GatedAction::InviteStaff => "Your scheduled downgrade to {$limits->label}{$when} caps team members at {$limits->maxSeats}, and you've reached it. Cancel the downgrade or remove a member to add more.",
        };
    }
}
