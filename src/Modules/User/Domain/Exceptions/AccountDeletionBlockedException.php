<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions;

use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\AccountDeletionBlocker;
use RuntimeException;

/**
 * Thrown by `DeleteOwnAccount` when the pre-flight `AccountDeletionPolicy`
 * check finds one or more outstanding commitments. The controller renders it
 * as a 422 with the blocker list, which the `/profile` delete modal shows
 * in place of the confirmation form.
 */
final class AccountDeletionBlockedException extends RuntimeException
{
    /**
     * @param  list<AccountDeletionBlocker>  $blockers
     */
    public function __construct(public readonly array $blockers)
    {
        parent::__construct('This account cannot be deleted while commitments are outstanding.');
    }
}
