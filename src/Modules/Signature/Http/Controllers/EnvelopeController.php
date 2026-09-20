<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers;

use App\Http\Concerns\EnforcesPlanGate;
use App\Http\Concerns\ResolvesActingUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\GatedAction;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\CheckEnvelopeProviderStatus;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\GetDraftEnvelope;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\ManageDraftEnvelopeSigners;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\PrepareEnvelopeForSignature;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\SyncEnvelopeRecipients;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeAlreadySentException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\UnsupportedDocumentFormatException;
use PactTrackSDK\SharedResources\Modules\Signature\Http\Requests\PrepareEnvelopeRequest;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Signer;
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
    use EnforcesPlanGate;

    public function __construct(
        private readonly PrepareEnvelopeForSignature $prepareEnvelopeForSignature,
        private readonly CheckEnvelopeProviderStatus $checkEnvelopeProviderStatus,
        private readonly GetDraftEnvelope $getDraftEnvelope,
        private readonly SyncEnvelopeRecipients $syncEnvelopeRecipients,
        private readonly ManageDraftEnvelopeSigners $manageDraftSigners,
    ) {
    }

    /**
     * GET /api/signature/documents/{document}/prepare
     *
     * Resumability read behind PrepareSignatureModal's signer-collection
     * step — returns the signers already committed to a still-open DocuSign
     * draft for this document, if one exists, so reopening "Prepare for
     * Signature" doesn't present an empty form for signers the tenant
     * already added. Never creates anything — see GetDraftEnvelope.
     */
    public function draftSigners(Request $request, Document $document): JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to view this document\'s signers.',
            ], 401);
        }

        Gate::forUser($user)->authorize('create', [Envelope::class, $document]);

        $envelope = $this->getDraftEnvelope->handle($document);
        $refreshFailed = false;

        if ($envelope !== null) {
            // DocuSign is the source of truth for recipients while the
            // envelope is a draft (see SyncEnvelopeRecipients). A failed
            // refresh degrades to the DB copy — never a 500.
            try {
                $this->syncEnvelopeRecipients->handle($envelope, $user);
                $envelope->load('signers');
            } catch (Throwable $e) {
                report($e);
                $refreshFailed = true;
            }
        }

        return response()->json([
            'envelope_id' => $envelope?->public_id,
            'refresh_failed' => $refreshFailed,
            'signers' => $envelope === null ? [] : $envelope->signers->map(fn (Signer $signer) => [
                'name' => $signer->name,
                'email' => $signer->email,
                'status' => $signer->status,
            ])->values(),
        ]);
    }

    /**
     * POST /api/signature/documents/{document}/sync-recipients
     *
     * Called by PrepareSignatureModal whenever the Sender View closes or
     * returns — with ANY event, including cancel/exit/save — so signers the
     * tenant added inside DocuSign are persisted even when they never
     * pressed Send. Keyed by document (the modal knows it) rather than
     * envelope. A DocuSign failure is a 200 with `refresh_failed: true`, not
     * an error: the caller is a fire-and-forget UX hook.
     */
    public function syncRecipients(Request $request, Document $document): JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to sync signers.',
            ], 401);
        }

        Gate::forUser($user)->authorize('create', [Envelope::class, $document]);

        $envelope = $this->getDraftEnvelope->handle($document);

        if ($envelope === null) {
            return response()->json(['synced' => false, 'refresh_failed' => false]);
        }

        try {
            $result = $this->syncEnvelopeRecipients->handle($envelope, $user, force: true);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['synced' => false, 'refresh_failed' => true]);
        }

        return response()->json($result + ['refresh_failed' => false]);
    }

    /**
     * POST   /api/signature/documents/{document}/draft-signers  {name, email}
     * DELETE /api/signature/documents/{document}/draft-signers  {email}
     *
     * Add/remove an additional signer — only while the document's envelope
     * has NOT been submitted (409 otherwise; the UI hides the controls). Both
     * return the resulting additional-signer list.
     */
    public function addDraftSigner(Request $request, Document $document): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255']]);

        return $this->mutateDraftSigners($request, $document, fn (Envelope $envelope, $user) =>
            $this->manageDraftSigners->add($envelope, $request->string('name')->toString(), $request->string('email')->toString(), $user));
    }

    public function removeDraftSigner(Request $request, Document $document): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        return $this->mutateDraftSigners($request, $document, fn (Envelope $envelope, $user) =>
            $this->manageDraftSigners->remove($envelope, $request->string('email')->toString(), $user));
    }

    private function mutateDraftSigners(Request $request, Document $document, callable $mutation): JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null) {
            return response()->json(['message' => 'You must be signed in to a provider account to change signers.'], 401);
        }

        Gate::forUser($user)->authorize('create', [Envelope::class, $document]);

        $envelope = $this->getDraftEnvelope->handle($document);

        if ($envelope === null) {
            return response()->json(['message' => 'This document has no open draft to change.'], 409);
        }

        try {
            $mutation($envelope, $user);
        } catch (EnvelopeAlreadySentException $e) {
            return response()->json(['message' => 'This document was already submitted — signers can no longer be changed.', 'submitted' => true], 409);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'DocuSign is unavailable right now. Please try again shortly.'], 502);
        }

        $clientEmail = strtolower((string) $document->client()->value('email'));

        return response()->json([
            'signers' => $envelope->signers()->get()
                ->reject(fn (Signer $s) => $s->provider_signer_id === '1' || strtolower($s->email) === $clientEmail)
                ->map(fn (Signer $s) => ['name' => $s->name, 'email' => $s->email, 'status' => $s->status])
                ->values(),
        ]);
    }

    /**
     * POST /api/signature/documents/{document}/prepare
     *
     * Creates (or reuses) a draft DocuSign envelope for this document, with
     * the document's client plus any co-signers from the request body's
     * `signers` array already attached as recipients, and returns a URL to
     * DocuSign's own hosted Sender View — embedded in an iframe by the
     * frontend (PrepareSignatureModal), where the tenant tags fields,
     * confirms recipients, and sends, using DocuSign's native UI.
     */
    public function prepare(PrepareEnvelopeRequest $request, Document $document): JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to prepare a document for signature.',
            ], 401);
        }

        Gate::forUser($user)->authorize('create', [Envelope::class, $document]);

        if ($response = $this->denyIfPlanGateFails(GatedAction::PrepareForSignature, $user)) {
            return $response;
        }

        try {
            $envelope = $this->prepareEnvelopeForSignature->handle($document, $request->coSigners());
            $senderViewUrl = $this->prepareEnvelopeForSignature->senderViewUrlFor($envelope);
        } catch (UnsupportedDocumentFormatException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (EnvelopeAlreadySentException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'DocuSign is unavailable right now. Please try again shortly.',
            ], 502);
        }

        return response()->json([
            'envelope_id' => $envelope->public_id,
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
