<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Domain\Exceptions;

use PactTraceSDK\SharedResources\Modules\Document\Domain\Enums\DocumentStatus;
use RuntimeException;

final class DocumentCannotBeDeletedException extends RuntimeException
{
    public static function forStatus(DocumentStatus $status): self
    {
        return new self(sprintf(
            'Document cannot be deleted while its status is "%s". Only a document with status "draft" may be deleted — cancel an in-flight document with Void instead.',
            $status->value,
        ));
    }
}
