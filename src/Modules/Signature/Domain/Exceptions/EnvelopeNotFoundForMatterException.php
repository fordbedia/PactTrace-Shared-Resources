<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by GetMatterEnvelopeDetail when a matter has no envelope at all,
 * or when an explicit `?envelope=` public_id doesn't belong to any of the
 * matter's own documents — the latter also covers a cross-tenant/cross-matter
 * probe, since the lookup is always scoped to the matter's own documents.
 * EnvelopeDetailController translates this to a 404.
 */
class EnvelopeNotFoundForMatterException extends RuntimeException
{
    public function __construct(int $matterId, ?string $envelopePublicId)
    {
        $message = $envelopePublicId === null
            ? "Matter [{$matterId}] has no signature envelope."
            : "Envelope [{$envelopePublicId}] does not belong to matter [{$matterId}].";

        parent::__construct($message);
    }
}
