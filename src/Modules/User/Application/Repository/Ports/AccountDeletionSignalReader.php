<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Application\Repository\Ports;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\AccountDeletionSignals;

/**
 * Reads the outstanding-commitment counts `AccountDeletionPolicy` needs for a
 * given provider (subscription status, documents out for signature, unaccepted
 * team/client invitations).
 *
 * A port so `DeleteOwnAccount` / `GetAccountDeletionEligibility` can be tested
 * against a fake, and so the cross-module reads (Document, Client) stay behind
 * one seam — same shape as `DepartingStaffReassignment`.
 *
 * Implemented by
 * Infrastructure\Repositories\Eloquent\EloquentAccountDeletionSignals.
 */
interface AccountDeletionSignalReader
{
    public function read(int $providerId): AccountDeletionSignals;
}
