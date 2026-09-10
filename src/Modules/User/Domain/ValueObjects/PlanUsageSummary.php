<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects;

/**
 * Live per-tenant usage against the four figures {@see PlanInfo} caps —
 * the read-model half of plan enforcement, {@see PlanInfo} being the limits
 * half. Built by Application\UseCases\GetPlanUsageSummary from real queries,
 * never estimated/cached here.
 *
 * Framework-free by the hexagonal rule in CLAUDE.md — same shape as
 * PlanInfo: a plain readonly data object with a snake_case toArray(), no
 * Illuminate\* imports.
 */
final class PlanUsageSummary
{
    public function __construct(
        public readonly int $activeClientCount,
        /** Admin + Staff seats used — the Owner is NOT counted (see PlanUsageReader). */
        public readonly int $activeStaffCount,
        public readonly int $storageUsedBytes,
        /** Envelopes with a non-draft status created since the start of the current Stripe billing cycle (`subscriptions.current_period_starts_at`, calendar month as a fallback) — a flow count that resets every cycle. */
        public readonly int $envelopesSentThisCycle,
        /**
         * The Admin / Staff split of {@see $activeStaffCount}. When built by
         * GetPlanUsageSummary from live queries,
         * `activeAdminCount + activeStaffRoleCount === activeStaffCount`.
         * Default 0 so hand-built policy-test fixtures need not set them.
         */
        public readonly int $activeAdminCount = 0,
        public readonly int $activeStaffRoleCount = 0,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'active_client_count' => $this->activeClientCount,
            'active_staff_count' => $this->activeStaffCount,
            'admin_count' => $this->activeAdminCount,
            'staff_count' => $this->activeStaffRoleCount,
            'storage_used_bytes' => $this->storageUsedBytes,
            'envelopes_sent_this_cycle' => $this->envelopesSentThisCycle,
        ];
    }
}
