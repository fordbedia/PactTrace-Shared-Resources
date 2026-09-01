<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions;

use RuntimeException;

/**
 * The name/password confirmation on the `/profile` delete modal did not
 * match the acting account. `reason` is `'name'` or `'password'`; the
 * controller maps it onto that field as a 422 validation error.
 */
final class AccountDeletionConfirmationException extends RuntimeException
{
    /**
     * @param  'name'|'password'  $reason
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Account deletion confirmation failed ({$reason}).");
    }
}
