<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports;

/**
 * The three {@see \PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\PlanUsageSummary}
 * figures that aren't already served by another module's own read port.
 * Storage is deliberately not here — GetPlanUsageSummary reads that straight
 * from the Document module's StorageUsageCalculator port instead of
 * reimplementing the SUM query a second time; see .claude/rules/plan.md,
 * "Ground rule: one source of truth, no parallel logic."
 *
 * Implemented by Infrastructure\Repositories\Eloquent\EloquentPlanUsageReader,
 * which reaches into the Client/Signature/User models directly — the same
 * pattern EloquentAccountDeletionSignals already uses for a cross-module,
 * read-only aggregate.
 */
interface PlanUsageReader
{
    /** Clients with `status = 'active'` for one tenant — what "active clients" means throughout .claude/rules/plan.md. */
    public function activeClientCount(int $providerId): int;

    /** Accepted, still-active provider-side users (owner + admin + staff) for one tenant — what a "seat" is. */
    public function activeStaffCount(int $providerId): int;

    /** Envelopes with a non-draft status created since the start of the current calendar month, for one tenant. */
    public function envelopesSentThisMonth(int $providerId): int;
}
