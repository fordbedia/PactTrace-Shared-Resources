<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers;

use App\Http\Concerns\ResolvesActingUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\FindNextPendingSignatureForClientUseCase;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\GenerateSigningEmbedTokenUseCase;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeNotSignableException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeSigningUnavailableException;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;

/**
 * Inbound adapter for Flow B (client-facing embedded signing) on
 * /portal — see .claude/rules/signature.md. Distinct from EnvelopeController
 * (the tenant-side "Prepare for Signature" link-out flow): different
 * audience, different authorisation shape (EnvelopePolicy::sign() vs
 * ::create()), kept as separate controllers rather than one growing to
 * cover both directions.
 *
 * Same LOCAL DEV ONLY caveat as every other controller here — see
 * App\Http\Concerns\ResolvesActingUser.
 */
class SigningController extends Controller
{
    use ResolvesActingUser;

    public function __construct(
        private readonly GenerateSigningEmbedTokenUseCase $generateSigningToken,
        private readonly FindNextPendingSignatureForClientUseCase $findNextPending,
    ) {
    }

    /**
     * GET /api/signature/pending
     *
     * Every envelope awaiting this client's signature — backs the "pending
     * documents" list on /portal. Scoped by the acting client's own id
     * directly in the query (same "viewAny checks the permission only,
     * index queries scope themselves" pattern as DocumentController::index —
     * see .claude/rules/user.md, "Policies").
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->client === null) {
            return response()->json([
                'message' => 'You must be signed in as a client to view pending signatures.',
            ], 401);
        }

        Gate::forUser($user)->authorize('viewAny', Envelope::class);

        $envelopes = Envelope::query()
            ->pendingForClient($user->client->id)
            ->with('document')
            ->get();

        return response()->json([
            'data' => $envelopes->map(fn (Envelope $envelope) => [
                'envelope_id' => $envelope->public_id,
                'status' => $envelope->status->value,
                'document_id' => $envelope->document?->id,
                'document_name' => $envelope->document?->name,
                'sent_at' => $envelope->sent_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * GET /api/signature/pending-next?exclude={envelopePublicId}
     *
     * The chaining step from the feature spec, Flow B step 4: after the
     * client's browser sees a signing complete (via postMessage), the
     * frontend calls this to decide where to send them next — another
     * pending envelope, or the "you're all done" screen if this returns no
     * data. Deliberately does not wait for the completion webhook to have
     * landed yet; `exclude` just keeps the envelope just signed out of its
     * own answer while that confirmation is still in flight.
     *
     * `remaining_count` is what the frontend's continue/later interstitial
     * (see .claude/rules/signature.md, "Deep-link chaining") uses to decide
     * whether to show that prompt at all — reuses the same
     * `pendingForClient` scope as the count below rather than a second
     * implementation of "how many are left."
     */
    public function pendingNext(Request $request): JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->client === null) {
            return response()->json([
                'message' => 'You must be signed in as a client to view pending signatures.',
            ], 401);
        }

        Gate::forUser($user)->authorize('viewAny', Envelope::class);

        $excludeEnvelopeId = $request->filled('exclude')
            ? Envelope::query()->where('public_id', $request->string('exclude'))->value('id')
            : null;

        $envelope = $this->findNextPending->handle($user->client, $excludeEnvelopeId);

        $remainingCount = Envelope::query()
            ->pendingForClient($user->client->id)
            ->when($excludeEnvelopeId !== null, fn ($query) => $query->where('id', '!=', $excludeEnvelopeId))
            ->count();

        if ($envelope === null) {
            return response()->json(['done' => true, 'remaining_count' => $remainingCount]);
        }

        return response()->json([
            'done' => false,
            'envelope_id' => $envelope->public_id,
            'document_id' => $envelope->document?->id,
            'document_name' => $envelope->document?->name,
            'remaining_count' => $remainingCount,
        ]);
    }

    /**
     * POST /api/signature/envelopes/{envelope}/signing-token
     *
     * Mints the short-lived embed token the frontend loads its signing
     * iframe with. Returns 503 (not 500) when the provider can't currently
     * serve embedding — see EnvelopeSigningUnavailableException, the known
     * case being DocuSign JWT Grant's consent_required response before the
     * impersonated user has completed the one-time consent flow.
     */
    public function signingToken(Request $request, Envelope $envelope): JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->client === null) {
            return response()->json([
                'message' => 'You must be signed in as a client to sign a document.',
            ], 401);
        }

        Gate::forUser($user)->authorize('sign', $envelope);

        try {
            $token = $this->generateSigningToken->handle($envelope, $user->client);
        } catch (EnvelopeNotSignableException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (EnvelopeSigningUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json([
            'token' => $token->token,
            'expires_at' => $token->expiresAt->format(DATE_ATOM),
            'signing_url' => $token->signingUrl,
        ]);
    }
}
