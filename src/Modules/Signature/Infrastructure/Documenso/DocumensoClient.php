<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Signature\Infrastructure\Documenso;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use PactTraceSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use RuntimeException;

/**
 * Adapter implementing ESignatureProvider against a self-hosted Documenso
 * instance's v2 envelope API. Bound to the port in SignatureProvider.
 *
 * Two distinct base URLs on purpose (see top-level CLAUDE.md, "Internal vs.
 * external URL distinction"):
 *
 *   - `$apiBaseUrl` (DOCUMENSO_API_URL) — server-to-server, e.g.
 *     http://documenso:3000 over the Docker network. Used for every call in
 *     this class.
 *   - `$publicBaseUrl` (DOCUMENSO_PUBLIC_URL) — browser-facing, e.g.
 *     https://signdoc.pacttrace.com. Used only to build the editorUrl()
 *     link a human's browser will actually open.
 */
class DocumensoClient implements ESignatureProvider
{
    public function __construct(
        private readonly string $apiBaseUrl,
        private readonly string $publicBaseUrl,
        private readonly string $apiKey,
    ) {
    }

    public function createDraftEnvelope(
        string $title,
        string $fileName,
        string $fileContents,
        ?string $externalId = null,
    ): string {
        $payload = array_filter([
            'type' => 'DOCUMENT',
            'title' => $title,
            'externalId' => $externalId,
        ], static fn ($value) => $value !== null);

        $response = $this->client()
            ->attach('files', $fileContents, $fileName, ['Content-Type' => 'application/pdf'])
            ->post('/api/v2/envelope/create', [
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                "Documenso envelope creation failed ({$response->status()}): {$response->body()}"
            );
        }

        $envelopeId = $response->json('id');

        if (! is_string($envelopeId) || $envelopeId === '') {
            throw new RuntimeException('Documenso envelope creation returned no envelope id.');
        }

        return $envelopeId;
    }

    /**
     * NOTE: verify this path against the running self-hosted instance —
     * Documenso's own document editor route is not part of the public v2
     * API reference and may differ by version. Confirmed for the "envelope"
     * data model (post documents/templates migration); adjust here if your
     * instance still resolves to a `/documents/{id}/edit`-style path.
     */
    public function editorUrl(string $providerEnvelopeId): string
    {
        return rtrim($this->publicBaseUrl, '/') . '/envelopes/' . $providerEnvelopeId . '/edit';
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->apiBaseUrl, '/'))
            ->withHeaders(['Authorization' => $this->apiKey])
            ->timeout(30);
    }
}
