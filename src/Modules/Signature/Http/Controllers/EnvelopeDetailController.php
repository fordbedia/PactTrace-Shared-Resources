<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Signature\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Matter\Infrastructure\Services\MatterActivityFeedBuilder;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\GetMatterEnvelopeDetail;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\PrepareMatterEnvelopesForSignature;
use PactTrackSDK\SharedResources\Modules\Signature\Application\UseCases\VoidEnvelopeHandler;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeNotFoundForMatterException;
use PactTrackSDK\SharedResources\Modules\Signature\Domain\Exceptions\EnvelopeCannotTransitionException;
use PactTrackSDK\SharedResources\Modules\Signature\Http\Requests\PrepareMatterEnvelopesRequest;
use PactTrackSDK\SharedResources\Modules\Signature\Http\Resources\EnvelopeDetailResource;
use PactTrackSDK\SharedResources\Modules\Signature\Models\Envelope;

/**
 * Inbound adapter for the envelope detail view
 * (/dashboard/signatures/matter/{matterId}) — see .claude/rules/signature.md.
 *
 * Unlike EnvelopeController/SigningController elsewhere in this module,
 * these routes sit behind real `auth:sanctum` middleware (see routes/api.php)
 * rather than the local-only ResolvesActingUser bypass — this is new,
 * staff-only surface built after the User module's Sanctum-based auth
 * landed (see .claude/rules/user.md, "The signed-in user payload"), so it
 * follows the modern pattern MattersController already uses instead of
 * carrying the older modules' pre-auth workaround forward.
 */
class EnvelopeDetailController extends Controller
{
    public function __construct(
        private readonly GetMatterEnvelopeDetail $getMatterEnvelopeDetail,
        private readonly VoidEnvelopeHandler $voidEnvelope,
        private readonly MatterActivityFeedBuilder $activityFeedBuilder,
        private readonly PrepareMatterEnvelopesForSignature $prepareMatterEnvelopes,
    ) {
    }

    /**
     * GET /api/v1/signature/matters/{matter}/envelope?envelope={publicId}
     *
     * `{matter}` binds by `Matter::public_id` — `Matter::getRouteKeyName()`'s
     * default, same identifier `/dashboard/matters/{matterId}` itself now
     * uses (see .claude/rules/matter.md, "Matter Detail is a real route").
     * This route used to bind by the internal auto-increment id on the
     * reasoning that a staff-only page has no need to hide it; that stopped
     * being the deciding factor once the Matter Detail page itself — which
     * every "View Signature" link on this page originates from — became a
     * public_id-keyed URL. Keeping this route on a different identifier
     * scheme than its own parent page was the actual inconsistency, not the
     * staff-only-ness. `envelope` is optional and, when given, is the target
     * Envelope's own public_id — see GetMatterEnvelopeDetail for the full
     * resolution rule this exists to serve (a matter can have more than one
     * envelope).
     */
    public function show(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('view', $matter);

        try {
            $envelope = $this->getMatterEnvelopeDetail->handle($matter, $request->string('envelope')->value() ?: null);
        } catch (EnvelopeNotFoundForMatterException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        Gate::authorize('view', $envelope);

        return (EnvelopeDetailResource::make($envelope))
            ->additional(['audit_trail' => $this->activityFeedBuilder->buildForEnvelope($envelope)])
            ->response();
    }

    /**
     * POST /api/v1/signature/envelopes/{envelope}/void
     *
     * `{envelope}` binds by Envelope::public_id, as everywhere else in this
     * module (see .claude/rules/signature.md, "Envelope public identifier").
     * The actual state-machine rule (a terminal envelope may never
     * transition again) lives on Envelope::markVoided() itself — this
     * controller only authorizes and translates that domain exception to a
     * 409, matching DocumentController::void()'s 422-on-domain-exception
     * shape (409 here since "already terminal" is a conflict with the
     * envelope's current state, not a validation failure of the request).
     */
    public function void(Request $request, Envelope $envelope): JsonResponse
    {
        Gate::authorize('void', $envelope);

        try {
            $envelope = $this->voidEnvelope->handle($envelope, $request->user(), $request->string('reason')->value() ?: null);
        } catch (EnvelopeCannotTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return (EnvelopeDetailResource::make($envelope->loadMissing(['document.matter', 'document.uploader', 'client', 'provider', 'signers'])))
            ->additional(['audit_trail' => $this->activityFeedBuilder->buildForEnvelope($envelope)])
            ->response();
    }

    /**
     * POST /api/v1/signature/matters/{matter}/prepare-all-envelopes
     *
     * "Prepare All for Signature" on the Matter Detail page — see
     * .claude/rules/matter.md. `{matter}` binds by `public_id`, same as
     * show() above. Authorized against the Matter itself (create requires a
     * Document, and there may be zero eligible ones) via the same
     * envelope.create permission the single-document path checks, since
     * this performs exactly that action, just for every eligible document
     * on the matter at once.
     *
     * Request body's optional `signers` (validated by
     * PrepareMatterEnvelopesRequest) is the JSON-object-keyed-by-document-id
     * co-signers map PrepareAllSignatureModal's signer-collection step
     * submits — see PrepareMatterEnvelopesForSignature::handle() and
     * .claude/rules/matter.md. Omitting it (or sending `{}`) behaves exactly
     * as before this existed: every envelope gets no co-signers.
     */
    public function prepareAll(PrepareMatterEnvelopesRequest $request, Matter $matter): JsonResponse
    {
        Gate::authorize('view', $matter);
        Gate::authorize('create', [Envelope::class]);

        $result = $this->prepareMatterEnvelopes->handle($matter, $request->coSignersByDocumentId());

        return response()->json($result);
    }
}
