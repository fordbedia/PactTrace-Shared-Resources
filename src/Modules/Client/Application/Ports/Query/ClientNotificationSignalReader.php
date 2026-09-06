<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Client\Application\Ports\Query;

/**
 * Reads the three live signals behind the client-portal notification bell.
 * Each figure comes from a different module's own table; the Eloquent adapter
 * reads those models directly (the same cross-module read `EloquentPlanUsageReader`
 * / `EloquentAccountDeletionSignals` already do), so no per-signal module has
 * to grow a bespoke "for this client" query it has no other use for.
 */
interface ClientNotificationSignalReader
{
    /**
     * Non-archived message threads for this client that hold at least one
     * message the client has not read (i.e. a staff reply). 0 when the client
     * has no portal user yet.
     */
    public function unreadMessageThreadCount(int $clientId): int;

    /**
     * Envelopes belonging to this client whose status is `sent` or
     * `partially_signed` — the same pair the account-deletion / workspace
     * blockers use for "documents out for signature".
     */
    public function documentsAwaitingSignatureCount(int $clientId): int;

    /**
     * Matters belonging to this client created within the recency window
     * (there is no per-matter "viewed" tracking, so recency is the only
     * available "new" signal).
     */
    public function newMatterCount(int $clientId): int;
}
