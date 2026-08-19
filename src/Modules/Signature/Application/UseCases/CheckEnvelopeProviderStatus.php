<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use RuntimeException;

/**
 * A live, read-only status check against the provider — used only for
 * optimistic UI feedback right after Flow A's Sender View iframe redirects
 * to its returnUrl (see .claude/rules/signature.md), so the tenant sees
 * "Sent" immediately rather than waiting on the Connect webhook to land.
 * Never writes to the local Envelope — that stays the webhook's exclusive
 * job (RecordSignatureCompletionUseCase). This is an active provider call,
 * not "trusting the return-URL redirect", which is what the feature spec
 * actually warns against.
 */
class CheckEnvelopeProviderStatus
{
    public function __construct(
        private readonly ESignatureProvider $eSignatureProvider,
    ) {
    }

    public function handle(Envelope $envelope): string
    {
        if ($envelope->provider_envelope_id === null) {
            throw new RuntimeException('Envelope has not been sent to the provider yet.');
        }

        return $this->eSignatureProvider->fetchEnvelopeStatus($envelope->provider_envelope_id);
    }
}
