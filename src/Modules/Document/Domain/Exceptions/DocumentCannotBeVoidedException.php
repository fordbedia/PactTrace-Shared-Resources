<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Domain\Exceptions;

use PactTraceSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use RuntimeException;

final class DocumentCannotBeVoidedException extends RuntimeException
{
    public static function forStatus(DocumentStatus $status): self
    {
        return new self(sprintf(
            'Document cannot be voided while its status is "%s". Only a document that is "sent" or "partially_signed" may be voided.',
            $status->value,
        ));
    }
}
