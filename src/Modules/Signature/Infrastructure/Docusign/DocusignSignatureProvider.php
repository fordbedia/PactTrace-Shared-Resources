<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Infrastructure\Docusign;

use DateTimeImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\EnvelopeRecipient;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\SigningToken;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\ValueObjects\WebhookEvent;
use RuntimeException;

/**
 * Adapter implementing ESignatureProvider against DocuSign's eSignature
 * REST API (v2.1), authenticated via JWT Grant. Bound to the port in
 * SignatureProvider.
 *
 * `RECIPIENT_ID` ('1') is the fixed recipientId views/recipient always
 * targets — the document's own client, always the first entry in a
 * `createDraftEnvelope()` recipients array (see ESignatureProvider). Any
 * additional co-signers get recipientId '2', '3', ... assigned positionally
 * in createDraftEnvelope() and never need a recipientViewUrl() of their
 * own — they sign via DocuSign's own emailed link (see EnvelopeRecipient).
 */
class DocusignSignatureProvider implements ESignatureProvider
{
    private const RECIPIENT_ID = '1';

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
    ): SigningToken {
        $response = $this->client()->post($this->envelopesUrl("/{$providerEnvelopeId}/views/recipient"), [
            'returnUrl' => $returnUrl,
            'authenticationMethod' => 'none',
            'email' => $recipient->email,
            'userName' => $recipient->name,
            'clientUserId' => $recipient->clientUserId,
            'recipientId' => self::RECIPIENT_ID,
        ]);

        $url = $this->extractViewUrl($response, 'recipient');

        return new SigningToken(
            token: self::RECIPIENT_ID,
            expiresAt: new DateTimeImmutable('+5 minutes'),
            signingUrl: $url,
            providerSignerId: self::RECIPIENT_ID,
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
