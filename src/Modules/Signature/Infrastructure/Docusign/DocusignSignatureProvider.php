<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Docusign;

use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\ProviderRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\SigningToken;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;
use RuntimeException;

/**
 * Adapter implementing ESignatureProvider against DocuSign's eSignature
 * REST API (v2.1), authenticated via JWT Grant. Bound to the port in
 * SignatureProvider.
 *
 * Every recipient gets a DocuSign `recipientId` assigned positionally in
 * `createDraftEnvelope()` — `'1'` for the document's own client, `'2'`,
 * `'3'`, ... for co-signers — persisted as Signer::provider_signer_id.
 * `recipientViewUrl()` targets whichever `$recipientId` its caller passes
 * (see ESignatureProvider's docblock); it no longer assumes `'1'`, since
 * co-signers now sign through PactTrack's own guest link and need a correct
 * embedded view for their own recipientId too — see
 * .claude/rules/signature.md, "Guest signers".
 */
class DocusignSignatureProvider implements ESignatureProvider
{
    private ?DocusignSession $session = null;

    public function __construct(
        private readonly JwtGrantAuthenticator $auth,
        private readonly string $webhookHmacKey = '',
    ) {
    }

    public function createDraftEnvelope(
        string $title,
        string $fileName,
        string $fileContents,
        array $recipients,
        ?string $externalId = null,
    ): string {
        $signers = [];

        foreach (array_values($recipients) as $index => $recipient) {
            $signers[] = $this->signerPayload($recipient, (string) ($index + 1));
        }

        $payload = [
            'emailSubject' => "Please sign: {$title}",
            'status' => 'created',
            'documents' => [[
                'documentId' => '1',
                'name' => $fileName,
                'fileExtension' => pathinfo($fileName, PATHINFO_EXTENSION) ?: 'pdf',
                'documentBase64' => base64_encode($fileContents),
            ]],
            'recipients' => ['signers' => $signers],
        ];

        if ($externalId !== null) {
            $payload['customFields'] = [
                'textCustomFields' => [[
                    'name' => 'pacttrack_document_id',
                    'value' => $externalId,
                    'show' => 'false',
                    'required' => 'false',
                ]],
            ];
        }

        $response = $this->client()->post($this->envelopesUrl(), $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                "DocuSign envelope creation failed ({$response->status()}): {$response->body()}"
            );
        }

        $envelopeId = $response->json('envelopeId');

        if (! is_string($envelopeId) || $envelopeId === '') {
            throw new RuntimeException('DocuSign envelope creation returned no envelope id.');
        }

        return $envelopeId;
    }

    public function senderViewUrl(string $providerEnvelopeId, string $returnUrl): string
    {
        $response = $this->client()->post($this->envelopesUrl("/{$providerEnvelopeId}/views/sender"), [
            'returnUrl' => $returnUrl,
        ]);

        return $this->extractViewUrl($response, 'sender');
    }

    public function recipientViewUrl(
        string $providerEnvelopeId,
        EnvelopeRecipient $recipient,
        string $returnUrl,
        string $recipientId,
    ): SigningToken {
        $response = $this->client()->post($this->envelopesUrl("/{$providerEnvelopeId}/views/recipient"), [
            'returnUrl' => $returnUrl,
            'authenticationMethod' => 'none',
            'email' => $recipient->email,
            'userName' => $recipient->name,
            'clientUserId' => $recipient->clientUserId,
            'recipientId' => $recipientId,
        ]);

        $url = $this->extractViewUrl($response, 'recipient');

        return new SigningToken(
            token: $recipientId,
            expiresAt: new DateTimeImmutable('+5 minutes'),
            signingUrl: $url,
            providerSignerId: $recipientId,
        );
    }

    public function applyBrand(string $providerEnvelopeId, ?string $brandId): void
    {
        if ($brandId === null) {
            return;
        }

        $response = $this->client()->put($this->envelopesUrl("/{$providerEnvelopeId}"), [
            'brandId' => $brandId,
        ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Applying DocuSign brand [{$brandId}] to envelope [{$providerEnvelopeId}] failed "
                . "({$response->status()}): {$response->body()}"
            );
        }
    }

    public function fetchEnvelopeStatus(string $providerEnvelopeId): string
    {
        $response = $this->client()->get($this->envelopesUrl("/{$providerEnvelopeId}"));

        if ($response->failed()) {
            throw new RuntimeException(
                "Fetching DocuSign envelope [{$providerEnvelopeId}] failed ({$response->status()}): {$response->body()}"
            );
        }

        $status = $response->json('status');

        if (! is_string($status) || $status === '') {
            throw new RuntimeException("DocuSign envelope [{$providerEnvelopeId}] response had no status.");
        }

        return strtolower($status);
    }

    /**
     * DocuSign Connect signs the raw payload with HMAC-SHA256, base64
     * encoded, delivered in an `X-DocuSign-Signature-1` header (Connect
     * supports up to 5 rotating keys/headers; PactTrack configures one). An
     * empty configured key always fails closed rather than accepting
     * unsigned payloads.
     */
    public function verifyWebhookSignature(string $rawPayload, ?string $signatureHeader): bool
    {
        if ($this->webhookHmacKey === '' || $signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', $rawPayload, $this->webhookHmacKey, true));

        return hash_equals($expected, $signatureHeader);
    }

    public function normalizeWebhookEvent(array $payload): WebhookEvent
    {
        return WebhookEvent::fromDocusignPayload($payload);
    }

    /**
     * DocuSign's `documents/combined` endpoint returns every document on the
     * envelope merged into one PDF; `certificate=true` appends the
     * Certificate of Completion to that same PDF, so one request produces
     * the full, final record — no second call needed to fetch the
     * certificate separately. This is the only ESignatureProvider method
     * that returns raw binary (not JSON) — extractViewUrl()/json() are not
     * used here, the response body is the file itself.
     */
    public function fetchCompletedDocument(string $providerEnvelopeId): string
    {
        $response = $this->client()->get(
            $this->envelopesUrl("/{$providerEnvelopeId}/documents/combined"),
            ['certificate' => 'true'],
        );

        if ($response->failed()) {
            throw new RuntimeException(
                "Fetching DocuSign combined document for envelope [{$providerEnvelopeId}] failed "
                . "({$response->status()}): {$response->body()}"
            );
        }

        return $response->body();
    }

    public function fetchRecipients(string $providerEnvelopeId): array
    {
        $response = $this->client()->get($this->envelopesUrl("/{$providerEnvelopeId}/recipients"));

        if ($response->failed()) {
            throw new RuntimeException(
                "Fetching DocuSign recipients for envelope [{$providerEnvelopeId}] failed "
                . "({$response->status()}): {$response->body()}"
            );
        }

        $recipients = [];

        foreach ((array) $response->json('signers', []) as $signer) {
            $recipientId = (string) ($signer['recipientId'] ?? '');
            $email = (string) ($signer['email'] ?? '');

            if ($recipientId === '' || $email === '') {
                continue;
            }

            $clientUserId = $signer['clientUserId'] ?? null;

            $recipients[] = new ProviderRecipient(
                recipientId: $recipientId,
                name: (string) ($signer['name'] ?? ''),
                email: $email,
                status: $this->normalizeRecipientStatus((string) ($signer['status'] ?? '')),
                clientUserId: is_string($clientUserId) && $clientUserId !== '' ? $clientUserId : null,
                routingOrder: (int) ($signer['routingOrder'] ?? 1) ?: 1,
            );
        }

        return $recipients;
    }

    public function embedRecipients(string $providerEnvelopeId, array $clientUserIdsByRecipientId): void
    {
        if ($clientUserIdsByRecipientId === []) {
            return;
        }

        $signers = [];

        foreach ($clientUserIdsByRecipientId as $recipientId => $clientUserId) {
            $signers[] = ['recipientId' => (string) $recipientId, 'clientUserId' => $clientUserId];
        }

        $response = $this->client()->put(
            $this->envelopesUrl("/{$providerEnvelopeId}/recipients"),
            ['signers' => $signers],
        );

        if ($response->failed()) {
            throw new RuntimeException(
                "Embedding DocuSign recipients on envelope [{$providerEnvelopeId}] failed "
                . "({$response->status()}): {$response->body()}"
            );
        }
    }

    public function addRecipient(string $providerEnvelopeId, EnvelopeRecipient $recipient, string $recipientId): void
    {
        $response = $this->client()->post(
            $this->envelopesUrl("/{$providerEnvelopeId}/recipients"),
            ['signers' => [$this->signerPayload($recipient, $recipientId)]],
        );

        if ($response->failed()) {
            throw new RuntimeException(
                "Adding recipient to DocuSign envelope [{$providerEnvelopeId}] failed ({$response->status()}): {$response->body()}"
            );
        }
    }

    public function removeRecipient(string $providerEnvelopeId, string $recipientId): void
    {
        $response = $this->client()->delete(
            $this->envelopesUrl("/{$providerEnvelopeId}/recipients"),
            ['signers' => [['recipientId' => $recipientId]]],
        );

        if ($response->failed()) {
            throw new RuntimeException(
                "Removing recipient from DocuSign envelope [{$providerEnvelopeId}] failed ({$response->status()}): {$response->body()}"
            );
        }
    }

    /** DocuSign recipient status -> Signer::status vocabulary. */
    private function normalizeRecipientStatus(string $status): string
    {
        return match (strtolower($status)) {
            'completed', 'signed' => 'signed',
            'delivered' => 'viewed',
            'declined' => 'declined',
            'sent' => 'sent',
            default => 'pending',
        };
    }

    /**
     * `clientUserId` is only sent for an embedded/captive recipient
     * (EnvelopeRecipient::$clientUserId !== null) — omitting the key
     * entirely for a remote co-signer is what tells DocuSign to email them
     * its own signing link instead of expecting an embedded view request.
     */
    private function signerPayload(EnvelopeRecipient $recipient, string $recipientId): array
    {
        $signer = [
            'recipientId' => $recipientId,
            'routingOrder' => '1',
            'email' => $recipient->email,
            'name' => $recipient->name,
        ];

        if ($recipient->clientUserId !== null) {
            $signer['clientUserId'] = $recipient->clientUserId;
        }

        return $signer;
    }

    private function extractViewUrl(Response $response, string $viewName): string
    {
        if ($response->failed()) {
            throw new RuntimeException(
                "DocuSign {$viewName} view request failed ({$response->status()}): {$response->body()}"
            );
        }

        $url = $response->json('url');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException("DocuSign {$viewName} view request returned no url.");
        }

        return $url;
    }

    private function session(): DocusignSession
    {
        return $this->session ??= $this->auth->authenticate();
    }

    private function client(): PendingRequest
    {
        return Http::withToken($this->session()->accessToken)->timeout(30);
    }

    private function envelopesUrl(string $suffix = ''): string
    {
        $session = $this->session();

        return "{$session->baseUri}/v2.1/accounts/{$session->accountId}/envelopes{$suffix}";
    }
}
