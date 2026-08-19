<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeNotSignableException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeSigningUnavailableException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\SigningToken;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use Throwable;

/**
 * Mints the embedded signing view URL behind the client portal's signing
 * iframe (Flow B) — see .claude/rules/signature.md. Authorization (is this
 * actor even allowed to sign this envelope) is the caller's job via
 * `Gate::forUser($user)->authorize('sign', $envelope)`; this class only
 * enforces envelope-state invariants that aren't about *who* is asking.
 */
class GenerateSigningEmbedTokenUseCase
{
    public function __construct(
        private readonly ESignatureProvider $eSignatureProvider,
    ) {
    }

    public function handle(Envelope $envelope, Client $client): SigningToken
    {
        if ($envelope->status->isTerminal()) {
            throw EnvelopeNotSignableException::terminal($envelope->status);
        }

        if ($envelope->provider_envelope_id === null) {
            throw EnvelopeNotSignableException::notYetSent();
        }

        $recipient = new EnvelopeRecipient(
            name: $client->name,
            email: $client->email,
            clientUserId: (string) $client->id,
        );

        try {
            $token = $this->eSignatureProvider->recipientViewUrl(
                $envelope->provider_envelope_id,
                $recipient,
                $this->returnUrl($envelope),
            );
        } catch (Throwable $e) {
            throw EnvelopeSigningUnavailableException::fromProviderFailure($e);
        }

        $signer = Signer::query()->firstOrNew([
            'envelope_id' => $envelope->id,
            'email' => $client->email,
        ]);

        if (! $signer->exists) {
            $signer->name = $client->name;
            $signer->routing_order = 1;
            $signer->status = 'pending';
        }

        $signer->provider_signer_id = $token->providerSignerId;
        $signer->save();

        return $token;
    }

    /**
     * Where DocuSign's Recipient View redirects the iframe once the client
     * finishes/declines/cancels — the same shared frontend return-detector
     * route Flow A uses (frontend/app/docusign-return), which immediately
     * postMessage()s the parent window rather than being a page anyone
     * actually looks at. `flow=recipient` is what portal/sign/page.js's
     * message listener keys off of to distinguish this from Flow A's use of
     * the same route.
     */
    private function returnUrl(Envelope $envelope): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            . '/docusign-return?flow=recipient&envelope=' . $envelope->id;
    }
}
