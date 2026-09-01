<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\User\Domain\Exceptions;

use RuntimeException;

/**
 * The "Current Password" field on the `/profile` password card did not match
 * the stored hash. The controller turns this into a 422 keyed on
 * `current_password`.
 */
final class InvalidCurrentPasswordException extends RuntimeException
{
    public function __construct(string $message = 'Your current password is incorrect.')
    {
        parent::__construct($message);
    }
}
