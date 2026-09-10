<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports;

/**
 * One table's contribution to a provider's stored-bytes total.
 *
 * This is the seam that keeps "what counts as storage" out of any single
 * action: every feature that persists an uploaded file (documents and message
 * attachments today; workspace logos, exported signed copies, … tomorrow)
 * ships one implementation of this interface in its own module's
 * Infrastructure layer, tags it as a `StorageSource` in that module's service
 * provider, and {@see \PactTrackSDK\SharedResources\Modules\User\Application\Services\StorageUsageAggregator}
 * folds them together. Nothing anywhere else should enumerate storage tables
 * by hand.
 *
 * Deliberately ordinary, type-checked application code rather than a
 * DB-driven registry of table/column/relationship-method strings — storage
 * accounting has to survive a schema refactor, stay visible to static
 * analysis and IDE find-usages, and be unit-testable without seeding rows.
 */
interface StorageSource
{
    /**
     * Sum of the bytes this source is responsible for, for one provider,
     * across every one of that provider's workspaces (the plan/subscription
     * a quota is checked against is per-provider, never per-workspace).
     */
    public function sumBytesForProvider(int $providerId): int;

    /**
     * Short, stable, machine-readable identifier — used only for
     * logging/debugging (e.g. the per-source breakdown a drift warning from
     * `storage:reconcile` prints). Not persisted, not a lookup key.
     */
    public function key(): string;
}
