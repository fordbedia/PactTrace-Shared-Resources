<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\RecordSignatureCompletionUseCase;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Ports\ESignatureProvider;
use PactTrackSDK\SharedResources\Modules\Signature\Models\SignatureWebhookEvent;

/**
 * Inbound adapter for DocuSign Connect's outbound webhooks — the source of
 * truth for envelope status changes, never the client's postMessage event
 * (see .claude/rules/signature.md, "Flow B" step 3). No auth middleware —
 * this is an unauthenticated external call — so verifyWebhookSignature() is
 * what stands in for authentication here.
 *
 * Idempotency lives entirely in the unique `payload_hash` on
 * signature_webhook_events (see the migration): firstOrCreate() means a
 * replayed identical payload is recognised and skipped, not reprocessed.
 */
class DocusignWebhookController extends Controller
{
    public function __construct(
        private readonly ESignatureProvider $eSignatureProvider,
        private readonly RecordSignatureCompletionUseCase $recordCompletion,
    ) {
    }

    /**
     * POST /api/signature/webhooks/docusign
     */
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();

        if (! $this->eSignatureProvider->verifyWebhookSignature($raw, $request->header('X-DocuSign-Signature-1'))) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'Malformed payload.'], 400);
        }

        $normalized = $this->eSignatureProvider->normalizeWebhookEvent($payload);

        $event = SignatureWebhookEvent::query()->firstOrCreate(
            ['payload_hash' => hash('sha256', $raw)],
            [
                'provider' => 'docusign',
                'event_type' => $normalized->eventType,
                'provider_envelope_id' => $normalized->providerEnvelopeId,
                'payload' => $payload,
            ],
        );

        if ($event->wasRecentlyCreated) {
            $this->recordCompletion->handle($normalized);
            $event->forceFill(['processed_at' => now()])->save();
        }

        return response()->json(['received' => true]);
    }
}
