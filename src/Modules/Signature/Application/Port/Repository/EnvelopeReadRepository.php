<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\Port\Repository;

use DateTimeInterface;

/**
 * Read-only aggregate access to `envelopes` for the provider dashboard —
 * "Signed This Month" and the "Signatures — Last 7 Days" chart on
 * `/dashboard` (see .claude/rules/signature.md). Small, single-purpose
 * counts; deliberately not the place for envelope lifecycle writes (those
 * stay in the module's use cases) and not a general envelope repository.
 *
 * Placed under `Application/Port/Repository` to match the Document module's
 * `DocumentRepository` convention; the Eloquent adapter is bound in
 * SignatureProvider.
 */
interface EnvelopeReadRepository
{
    /**
     * How many of a tenant's envelopes reached `completed` with a
     * `completed_at` in the half-open range [`$from`, `$to`) — `$to` null
     * meaning "up to now". Backs the "Signed This Month" card and its
     * "vs last month" comparison.
     */
    public function countCompletedBetween(int $providerId, DateTimeInterface $from, ?DateTimeInterface $to = null): int;

    /**
     * `completed` envelope counts for a tenant, grouped by the calendar day
     * of `completed_at`, for every day at or after `$since`. Sparse — only
     * days with at least one completion appear; the caller zero-fills the
     * full 7-day window.
     *
     * @return array<string, int> keyed by `Y-m-d`
     */
    public function completedCountByDaySince(int $providerId, DateTimeInterface $since): array;
}
