<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Fake;

use DateTimeImmutable;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\SigningToken;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;

/**
 * In-memory stand-in for ESignatureProvider — no network calls, deterministic
 * enough to assert against. Bound in tests (see Signature module Tests/) and
 * available for local dev via SIGNATURE_PROVIDER=fake so the team is never
 * blocked on a real DocuSign sandbox account/consent — see
 * .claude/rules/signature.md.
 */
class FakeSignatureProvider implements ESignatureProvider
{
    public function createDraftEnvelope(
        string $title,
        string $fileName,
        string $fileContents,
        array $recipients,
        ?string $externalId = null,
    ): string {
        return 'fake-envelope-' . Str::uuid();
    }

    public function senderViewUrl(string $providerEnvelopeId, string $returnUrl): string
    {
        return "https://fake.docusign.test/sender/{$providerEnvelopeId}?returnUrl=" . urlencode($returnUrl);
    }

    public function recipientViewUrl(
        string $providerEnvelopeId,
        EnvelopeRecipient $recipient,
        string $returnUrl,
    ): SigningToken {
        $token = 'fake-token-' . Str::uuid();

        return new SigningToken(
            token: $token,
            expiresAt: new DateTimeImmutable('+5 minutes'),
            signingUrl: "https://fake.docusign.test/recipient/{$providerEnvelopeId}/{$token}",
            providerSignerId: 'fake-recipient-' . md5($recipient->email),
        );
    }

    public function applyBrand(string $providerEnvelopeId, ?string $brandId): void
    {
        // No-op — nothing to assert against a fake provider for a
        // fire-and-forget branding call.
    }

    public function fetchEnvelopeStatus(string $providerEnvelopeId): string
    {
        return 'sent';
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader): bool
    {
        return true;
    }

    public function normalizeWebhookEvent(array $payload): WebhookEvent
    {
        return WebhookEvent::fromDocusignPayload($payload);
    }
}
