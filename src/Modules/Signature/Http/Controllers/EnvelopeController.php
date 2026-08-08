<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Signature\Http\Controllers;

use App\Http\Concerns\ResolvesActingUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\Signature\Application\UseCases\PrepareEnvelopeForSignature;
use PactTraceSDK\SharedResources\Modules\Signature\Models\Envelope;

/**
 * Inbound adapter for the "Prepare for Signature" action on
 * /dashboard/documents (frontend/app/dashboard/documents/page.js).
 *
 * Deliberately thin: authorises, delegates to the use case, shapes the
 * response. No Documenso-specific logic lives here — that is entirely
 * behind ESignatureProvider / DocumensoClient, per the hexagonal rule in
 * the top-level CLAUDE.md.
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
    ) {
    }

    /**
     * POST /api/signature/documents/{document}/prepare
     *
     * Creates (or reuses) a draft Documenso envelope for this document and
     * returns a link to Documenso's own hosted editor, where the attorney
     * drags on signature/date fields and adds recipients using Documenso's
     * native UI. The frontend opens that URL in a new tab — see
     * .claude/rules/signature.md for why this isn't an embedded iframe.
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

        $envelope = $this->prepareEnvelopeForSignature->handle($document);

        return response()->json([
            'envelope_id' => $envelope->id,
            'status' => $envelope->status,
            'editor_url' => $this->prepareEnvelopeForSignature->editorUrlFor($envelope),
        ]);
    }
}
