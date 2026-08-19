<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers;

use App\Http\Concerns\ResolvesActingUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\CheckEnvelopeProviderStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\PrepareEnvelopeForSignature;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\UnsupportedDocumentFormatException;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use Throwable;

/**
 * Inbound adapter for Flow A (tenant/staff prepares a document for
 * signature) on /dashboard/documents — see .claude/rules/signature.md.
 *
 * Deliberately thin: authorises, delegates to the use case, shapes the
 * response. No DocuSign-specific logic lives here — that is entirely
 * behind ESignatureProvider / DocusignSignatureProvider, per the hexagonal
 * rule in the top-level CLAUDE.md.
 *
 * NOTE: no `auth` middleware is attached in routes/api.php yet because the
 * backend has no auth scaffolding at all (see top-level CLAUDE.md,
 * "Current backend status"). `resolveActingUser()` (see
 * App\Http\Concerns\ResolvesActingUser) is a LOCAL-ONLY bypass standing in
 * for a real session — wire the route through whatever guard/middleware
 * auth introduces, and delete that trait's call sites, before shipping
 * this past local dev.
 */
class EnvelopeController extends Controller
{
    use ResolvesActingUser;

    public function __construct(
        private readonly PrepareEnvelopeForSignature $prepareEnvelopeForSignature,
        private readonly CheckEnvelopeProviderStatus $checkEnvelopeProviderStatus,
    ) {
    }

    /**
     * POST /api/signature/documents/{document}/prepare
     *
     * Creates (or reuses) a draft DocuSign envelope for this document, with
     * the document's client already attached as its recipient, and returns
     * a URL to DocuSign's own hosted Sender View — embedded in an iframe by
     * the frontend (PrepareSignatureModal), where the tenant tags fields,
     * confirms the recipient, and sends, using DocuSign's native UI.
     */
    public function prepare(Request $request, Document $document): JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to prepare a document for signature.',
            ], 401);
        }

        Gate::forUser($user)->authorize('create', [Envelope::class, $document]);

        try {
            $envelope = $this->prepareEnvelopeForSignature->handle($document);
            $senderViewUrl = $this->prepareEnvelopeForSignature->senderViewUrlFor($envelope);
        } catch (UnsupportedDocumentFormatException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'DocuSign is unavailable right now. Please try again shortly.',
            ], 502);
        }

        return response()->json([
            'envelope_id' => $envelope->id,
            'status' => $envelope->status,
            'sender_view_url' => $senderViewUrl,
        ]);
    }

    /**
     * GET /api/signature/envelopes/{envelope}/status
     *
     * A live read against DocuSign — backs the optimistic "Sent" UI
     * feedback PrepareSignatureModal shows right after the Sender View
     * iframe's returnUrl fires, before the Connect webhook has necessarily
     * landed yet. Never writes to the local Envelope — see
     * CheckEnvelopeProviderStatus.
     */
    public function status(Request $request, Envelope $envelope): JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to view envelope status.',
            ], 401);
        }

        Gate::forUser($user)->authorize('view', $envelope);

        try {
            $status = $this->checkEnvelopeProviderStatus->handle($envelope);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Could not reach DocuSign to check status right now.',
            ], 502);
        }

        return response()->json(['status' => $status]);
    }
}
