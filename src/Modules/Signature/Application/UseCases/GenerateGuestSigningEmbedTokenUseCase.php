<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases;

use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeNotSignableException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeSigningUnavailableException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\SigningToken;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
use RuntimeException;
use Throwable;

/**
 * Mints the embedded signing view URL for a guest (no PactTrack account)
 * co-signer — the guest-link counterpart to GenerateSigningEmbedTokenUseCase,
 * which does the same thing for the document's own portal-authenticated
 * client. Kept as a separate class rather than branching the existing one:
 * the two take a different identity input (Signer vs. Client) and have a
 * different authorization story (a resolved guest token vs.
 * Gate::forUser()->authorize('sign', ...)) — see GuestSigningController and
 * .claude/rules/signature.md, "Guest signers".
 *
 * Authorization (is this token actually valid for this envelope) is the
 * caller's job via GuestSigningTokenService::resolve(); this class only
 * enforces envelope-state invariants, same division of responsibility as
 * GenerateSigningEmbedTokenUseCase.
 */
class GenerateGuestSigningEmbedTokenUseCase
{
    public function __construct(
        private readonly ESignatureProvider $eSignatureProvider,
    ) {
    }

    public function handle(Envelope $envelope, Signer $signer): SigningToken
    {
        if ($envelope->status->isTerminal()) {
            throw EnvelopeNotSignableException::terminal($envelope->status);
        }

        if ($envelope->provider_envelope_id === null) {
            throw EnvelopeNotSignableException::notYetSent();
        }

        // clientUserId is the signer's own email — the same stable value
        // PrepareEnvelopeForSignature registered this co-signer with at
        // envelope creation. See EnvelopeRecipient's docblock.
        $recipient = new EnvelopeRecipient(
            name: $signer->name,
            email: $signer->email,
            clientUserId: $signer->email,
        );

        // A guest Signer row always already has its DocuSign recipientId
        // ('2', '3', ...) from PrepareEnvelopeForSignature::createSignerRows()
        // — GuestSigningTokenService::resolve() only ever returns an
        // existing row, never a new one. Falling back to '1' would target
        // the primary recipient's view by mistake, so this deliberately has
        // no fallback the way GenerateSigningEmbedTokenUseCase's does.
        $recipientId = $signer->provider_signer_id
            ?? throw new RuntimeException("Guest Signer [{$signer->id}] has no provider_signer_id.");

        try {
            $token = $this->eSignatureProvider->recipientViewUrl(
                $envelope->provider_envelope_id,
                $recipient,
                $this->returnUrl($envelope),
                $recipientId,
            );
        } catch (Throwable $e) {
            throw EnvelopeSigningUnavailableException::fromProviderFailure($e);
        }

        $signer->provider_signer_id = $token->providerSignerId;
        $signer->save();

        return $token;
    }

    /**
     * Same shared frontend return-detector route Flow B's authenticated
     * path uses (frontend/app/docusign-return) — a guest signer's browser
     * is redirected there exactly the same way, `flow=recipient`.
     */
    private function returnUrl(Envelope $envelope): string
    {
        return rtrim((string) config('app.frontend_url'), '/')
            . '/docusign-return?flow=recipient&envelope=' . $envelope->public_id;
    }
}
