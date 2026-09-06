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
        public readonly int $activeStaffCount,
        public readonly int $storageUsedBytes,
        /** Envelopes with a non-draft status created since the start of the current calendar month — a flow count, resets every cycle. */
        public readonly int $envelopesSentThisMonth,
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
            'storage_used_bytes' => $this->storageUsedBytes,
            'envelopes_sent_this_month' => $this->envelopesSentThisMonth,
        ];
    }
}
