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
     * Document storage allowance for this plan, in bytes — backs the STORAGE
     * indicator on /dashboard and /dashboard/documents (see
     * .claude/rules/document.md).
     *
     * A display figure only: nothing enforces it at upload time yet. When that
     * changes, enforce against this same value so the indicator and the limit
     * can't drift.
     */
    public function storageLimitBytes(): int
    {
        return match ($this) {
            self::Starter => 5 * 1024 * 1024 * 1024,     // 5 GB
            self::Professional => 50 * 1024 * 1024 * 1024, // 50 GB
            self::Firm => 200 * 1024 * 1024 * 1024,      // 200 GB
        };
    }
}
