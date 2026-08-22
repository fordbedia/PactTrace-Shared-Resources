<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use PactTrackSDK\SharedResources\Modules\Signature\Application\Services\GuestSigningTokenService;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\GenerateGuestSigningEmbedTokenUseCase;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeNotSignableException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeSigningUnavailableException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\GuestSigningTokenUnavailableException;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;

/**
 * Inbound adapter for guest (no PactTrack account) signing — see
 * .claude/rules/signature.md, "Guest signers". Deliberately separate from
 * SigningController: that controller's authorization is
 * `Gate::forUser($user)->authorize('sign', $envelope)`, which requires a
 * real User; a guest has none, and the token match performed by
 * GuestSigningTokenService::resolve() *is* the authorization here — there
 * is no user to gate.
 *
 * No auth middleware, same as the rest of this module's routes today — see
 * the note in routes/api.php.
 */
class GuestSigningController extends Controller
{
    public function __construct(
        private readonly GuestSigningTokenService $guestSigningTokenService,
        private readonly GenerateGuestSigningEmbedTokenUseCase $generateGuestSigningToken,
    ) {
    }

    /**
     * POST /api/signature/envelopes/{envelope}/guest-signing-token
     *
     * {envelope} resolves by Envelope::public_id (see
     * Envelope::getRouteKeyName()), so a guest link never exposes or
     * accepts the internal auto-increment id. `signingLinkToken` additionally
     * scopes the request to one specific Signer on that envelope — see
     * GuestSigningTokenService::resolve().
     */
    public function signingToken(Request $request, Envelope $envelope): JsonResponse
    {
        $request->validate([
            'signingLinkToken' => ['required', 'string'],
        ]);

        try {
            $signer = $this->guestSigningTokenService->resolve($envelope, $request->string('signingLinkToken')->toString());
        } catch (GuestSigningTokenUnavailableException $e) {
            $status = $e->reason === 'invalid' ? 404 : 410;

            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $status);
        }

        try {
            $token = $this->generateGuestSigningToken->handle($envelope, $signer);
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

    /**
     * POST /api/signature/envelopes/{envelope}/guest-signer-status
     *
     * The guest-link counterpart to SigningController::signerStatus() — see
     * that method's docblock and .claude/rules/signature.md, "Never
     * contradict the authoritative signed status". Uses
     * `GuestSigningTokenService::findByToken()`, not `resolve()`: a guest
     * who already finished signing has a *consumed* token by design (see
     * "Guest signers" — consumption happens the same moment
     * `recordSignersCompleted()` marks them `signed`), and `resolve()`
     * would reject that as unusable for signing again, which is correct
     * for the signing action but wrong here — a consumed token is exactly
     * how a just-finished guest identifies themselves for this read.
     */
    public function signerStatus(Request $request, Envelope $envelope): JsonResponse
    {
        $request->validate([
            'signingLinkToken' => ['required', 'string'],
        ]);

        $signer = $this->guestSigningTokenService->findByToken(
            $envelope,
            $request->string('signingLinkToken')->toString(),
        );

        if ($signer === null) {
            return response()->json(['message' => 'This signing link is not valid.', 'reason' => 'invalid'], 404);
        }

        return response()->json([
            'signed' => $signer->status === 'signed',
            'declined' => $signer->status === 'declined',
            'envelope_status' => $envelope->status->value,
        ]);
    }
}
