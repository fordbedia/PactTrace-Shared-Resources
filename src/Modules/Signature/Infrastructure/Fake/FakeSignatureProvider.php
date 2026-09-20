<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Fake;

use DateTimeImmutable;
use Illuminate\Support\Str;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\ProviderRecipient;
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
    /**
     * Recipients per provider envelope id. createDraftEnvelope() seeds it
     * from the recipients it is given; tests mutate it directly to simulate
     * signers added/removed inside DocuSign's own UI.
     *
     * @var array<string, ProviderRecipient[]>
     */
    public array $recipients = [];

    /** When set, fetchRecipients() throws it — simulates a DocuSign outage. */
    public ?\Throwable $recipientsFailure = null;

    /** @var array<int, array<string, string>> */
    public array $embedCalls = [];

    public function createDraftEnvelope(
        string $title,
        string $fileName,
        string $fileContents,
        array $recipients,
        ?string $externalId = null,
    ): string {
        $id = 'fake-envelope-' . Str::uuid();

        foreach (array_values($recipients) as $index => $recipient) {
            $this->recipients[$id][] = new ProviderRecipient(
                recipientId: (string) ($index + 1),
                name: $recipient->name,
                email: $recipient->email,
                status: 'pending',
                clientUserId: $recipient->clientUserId,
            );
        }

        return $id;
    }

    public function senderViewUrl(string $providerEnvelopeId, string $returnUrl): string
    {
        return "https://fake.docusign.test/sender/{$providerEnvelopeId}?returnUrl=" . urlencode($returnUrl);
    }

    public function recipientViewUrl(
        string $providerEnvelopeId,
        EnvelopeRecipient $recipient,
        string $returnUrl,
        string $recipientId,
    ): SigningToken {
        $token = 'fake-token-' . Str::uuid();

        return new SigningToken(
            token: $token,
            expiresAt: new DateTimeImmutable('+5 minutes'),
            signingUrl: "https://fake.docusign.test/recipient/{$providerEnvelopeId}/{$token}",
            providerSignerId: $recipientId,
        );
    }

    public function applyBrand(string $providerEnvelopeId, ?string $brandId): void
    {
        // No-op — nothing to assert against a fake provider for a
        // fire-and-forget branding call.
    }

    public function fetchEnvelopeStatus(string $providerEnvelopeId): string
    {
        return $this->envelopeStatus;
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader): bool
    {
        return true;
    }

    public function normalizeWebhookEvent(array $payload): WebhookEvent
    {
        return WebhookEvent::fromDocusignPayload($payload);
    }

    public function fetchCompletedDocument(string $providerEnvelopeId): string
    {
        return "%FAKE-SIGNED-PDF%\ncontent-for-envelope:{$providerEnvelopeId}";
    }

    public function fetchRecipients(string $providerEnvelopeId): array
    {
        if ($this->recipientsFailure !== null) {
            throw $this->recipientsFailure;
        }

        return $this->recipients[$providerEnvelopeId] ?? [];
    }

    public function embedRecipients(string $providerEnvelopeId, array $clientUserIdsByRecipientId): void
    {
        $this->embedCalls[] = $clientUserIdsByRecipientId;

        foreach ($this->recipients[$providerEnvelopeId] ?? [] as $i => $recipient) {
            if (isset($clientUserIdsByRecipientId[$recipient->recipientId])) {
                $this->recipients[$providerEnvelopeId][$i] = new ProviderRecipient(
                    $recipient->recipientId, $recipient->name, $recipient->email, $recipient->status,
                    $clientUserIdsByRecipientId[$recipient->recipientId], $recipient->routingOrder,
                );
            }
        }
    }

    /** Status fetchEnvelopeStatus() reports; tests set 'created' to simulate an unsent draft. */
    public string $envelopeStatus = 'sent';

    public function addRecipient(string $providerEnvelopeId, EnvelopeRecipient $recipient, string $recipientId): void
    {
        $this->recipients[$providerEnvelopeId][] = new ProviderRecipient(
            $recipientId, $recipient->name, $recipient->email, 'pending', $recipient->clientUserId,
        );
    }

    public function removeRecipient(string $providerEnvelopeId, string $recipientId): void
    {
        $this->recipients[$providerEnvelopeId] = array_values(array_filter(
            $this->recipients[$providerEnvelopeId] ?? [],
            fn (ProviderRecipient $r) => $r->recipientId !== $recipientId,
        ));
    }
}
