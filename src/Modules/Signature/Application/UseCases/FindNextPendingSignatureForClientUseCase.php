<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;

/**
 * Backs both the client portal's pending-documents list and the "what's
 * next" chaining step after a signature (Flow B, step 4 in the feature
 * spec). `$excludeEnvelopeId` is the envelope the client just finished (or
 * is currently viewing), so it never chains back into itself while the
 * webhook confirming that signature is still in flight — see
 * .claude/rules/signature.md on why this must not block on that webhook.
 */
class FindNextPendingSignatureForClientUseCase
{
    public function handle(Client $client, ?int $excludeEnvelopeId = null): ?Envelope
    {
        return Envelope::query()
            ->pendingForClient($client->id)
            ->when($excludeEnvelopeId !== null, fn ($query) => $query->where('id', '!=', $excludeEnvelopeId))
            ->with('document')
            ->first();
    }
}
