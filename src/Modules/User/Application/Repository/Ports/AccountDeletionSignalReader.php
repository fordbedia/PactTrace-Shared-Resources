<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\AccountDeletionSignals;

/**
 * Reads the outstanding-commitment counts `AccountDeletionPolicy` needs for a
 * given provider (subscription status, documents out for signature) plus the
 * informational active-staff count.
 *
 * A port so `DeleteOwnAccount` / `GetAccountDeletionEligibility` can be tested
 * against a fake, and so the cross-module reads (Document) stay behind one
 * seam — same shape as `DepartingStaffReassignment`.
 *
 * Implemented by
 * Infrastructure\Repositories\Eloquent\EloquentAccountDeletionSignals.
 */
interface AccountDeletionSignalReader
{
    /**
     * @param  int|null  $excludeUserId  The acting user, left out of the
     *                                   active-staff count (they are the one
     *                                   being deactivated).
     */
    public function read(int $providerId, ?int $excludeUserId = null): AccountDeletionSignals;
}
