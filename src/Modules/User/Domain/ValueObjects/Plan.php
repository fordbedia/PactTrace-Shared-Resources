<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * A PactTrack subscription plan.
 *
 * The single source of truth for "what plans exist" and "what each plan
 * allows". `providers.plan` / `subscriptions.plan` store the string; this enum
 * is what code reasons about. Framework-free by the hexagonal rule in
 * CLAUDE.md — same shape as WorkspaceType.
 *
 * Before this existed, the plan list was restated in StoreRegistrationRequest's
 * validation rule and the storage allowances lived in a separate, independently
 * maintained config array — two (soon three) sources of truth for one concept.
 */
enum Plan: string
{
    case Starter = 'starter';
    case Professional = 'professional';
    case Firm = 'firm';

    /**
     * The plan assumed when none can be resolved — the smallest tier, so a
     * tenant we can't identify is never shown a larger allowance than anyone
     * actually buys.
     */
    public static function default(): self
    {
        return self::Starter;
    }

    /**
     * Every value — for validation rules and test assertions.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $plan): string => $plan->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Starter => 'Starter',
            self::Professional => 'Professional',
            self::Firm => 'Firm',
        };
    }

    /**
     * Everything this plan allows — seats, quotas, feature flags. The one way
     * to ask "what does this tier get"; see {@see PlanInfo} and
     * .claude/rules/plan.md.
     *
     * Storage allowance (previously `Plan::storageLimitBytes()`, read directly
     * by the Document module's STORAGE indicator) now lives on
     * `PlanInfo->storageLimitBytes`.
     */
    public function info(): PlanInfo
    {
        return PlanInfo::for($this);
    }
}
