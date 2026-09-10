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

    /**
     * Accepted, still-active Admin + Staff users for one tenant — what a
     * "seat" is. The Owner is deliberately NOT counted (policy, Ed
     * 2026-09-06): a seat is for people the owner brings on, so
     * Starter/Professional's `maxSeats: 1` fits the owner plus one teammate.
     */
    public function activeStaffCount(int $providerId): int;

    /**
     * Just the Admins. Invariant: for the same tenant at the same instant,
     * {@see activeAdminCount()} + {@see activeStaffRoleCount()} === {@see activeStaffCount()}
     * (all three are live COUNTs against the same `users` rows).
     */
    public function activeAdminCount(int $providerId): int;

    /** Just the Staff — the complement of {@see activeAdminCount()}. */
    public function activeStaffRoleCount(int $providerId): int;

    /**
     * Envelopes with a non-draft status created since the start of the tenant's
     * current **Stripe billing cycle** (`subscriptions.current_period_starts_at`),
     * falling back to the start of the calendar month when that is null (a
     * card-less trial that never reached Stripe Checkout). `maxEnvelopesPerMonth`
     * is a flow limit that must reset when Stripe bills, not on the 1st.
     */
    public function envelopesSentThisCycle(int $providerId): int;
}
