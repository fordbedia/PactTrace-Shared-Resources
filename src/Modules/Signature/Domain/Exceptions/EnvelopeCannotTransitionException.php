<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions;

use PactTrackSDK\SharedResources\Modules\Signature\Domain\Enums\EnvelopeStatus;
use RuntimeException;

class EnvelopeCannotTransitionException extends RuntimeException
{
    public static function terminal(EnvelopeStatus $status): self
    {
        return new self("Envelope is already in a terminal state [{$status->value}] and cannot transition further.");
    }
}
