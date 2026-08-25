<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Http\Controllers;

use App\Http\Concerns\ResolvesActingUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Document\Application\Action\GetStorageUsageAction;
use PactTrackSDK\SharedResources\Modules\Document\Application\Action\ListDocumentsAction;
use PactTrackSDK\SharedResources\Modules\Document\Application\Action\UploadDocumentAction;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\ArchiveDocumentHandler;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\DeleteDocumentHandler;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\UnarchiveDocumentHandler;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\VoidDocumentHandler;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Exceptions\DocumentCannotBeDeletedException;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Exceptions\DocumentCannotBeVoidedException;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Services\ByteFormatter;
use PactTrackSDK\SharedResources\Modules\Document\Application\DTO\DocumentData;
use PactTrackSDK\SharedResources\Modules\Document\Application\DTO\DocumentListData;
use PactTrackSDK\SharedResources\Modules\Document\Http\Requests\StoreDocumentRequest;
use PactTrackSDK\SharedResources\Modules\Document\Http\Resources\DocumentResource;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound adapter for the Document Center on /dashboard/documents. Thin by
 * design — validation lives in StoreDocumentRequest, orchestration in
 * UploadDocumentAction/ListDocumentsAction (Application/Action/), storage
 * and persistence behind the DocumentStorage/DocumentRepository ports; this
 * class only authorizes and shapes the response.
 *
 * NOTE: like EnvelopeController, this has no auth middleware yet because
 * the backend has no auth scaffolding at all (see top-level CLAUDE.md,
 * "Current backend status"). `documents.uploaded_by` and
 * `documents.provider_id` are both NOT NULL foreign keys, so store() cannot
 * succeed at all without a real user — `resolveActingUser()` (see
 * App\Http\Concerns\ResolvesActingUser) is a LOCAL-ONLY bypass that
 * resolves DEV_ACTING_USER_ID instead, so this can actually be exercised
 * against database/seeders/DevTenantSeeder.php before login exists.
 */
class DocumentController extends Controller
{
    use ResolvesActingUser;

    public function __construct(
        private readonly UploadDocumentAction $uploadDocument,
        private readonly ListDocumentsAction $listDocuments,
        private readonly GetStorageUsageAction $storageUsage,
        private readonly ByteFormatter $bytes,
        private readonly DeleteDocumentHandler $deleteDocument,
        private readonly ArchiveDocumentHandler $archiveDocument,
        private readonly UnarchiveDocumentHandler $unarchiveDocument,
        private readonly VoidDocumentHandler $voidDocument,
    ) {
    }

    /**
     * GET /api/documents?folder_id=&page=&per_page=
     *
     * No `folder_id` (or "all") returns every document the actor can see.
     * A real `folder_id` returns that folder's documents plus every
     * document nested under it at any depth — see ListDocumentsAction.
     *
     * Paginated server-side (Laravel's LengthAwarePaginator, so the response
     * carries the standard `links`/`meta` blocks alongside `data`) — same
     * shape as MattersController::index(). `per_page` defaults to 15 and is
     * clamped in DocumentListData; `page` is 1-indexed.
     */
    public function index(Request $request): AnonymousResourceCollection|Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to view documents.',
            ], 401);
        }

        Gate::forUser($user)->authorize('viewAny', Document::class);

        return DocumentResource::collection(
            $this->listDocuments->handle($user, DocumentListData::fromRequest($request))
        );
    }

    /**
     * GET /api/documents/storage
     *
     * The STORAGE indicator in the /dashboard/documents sidebar. Gated on
     * `viewAny` like index() — this is the same read ("can this actor see
     * this tenant's documents"), expressed as one aggregate instead of rows.
     *
     * Returns raw byte counts *and* pre-formatted labels: the raw numbers so
     * the frontend can drive the progress bar (and so a future quota check
     * has something exact to compare), the labels so "6.2 GB of 10 GB" is
     * worded identically everywhere it appears (same reasoning as
     * MatterCountFormatter on the matters stat cards).
     */
    public function storage(Request $request): JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to view storage usage.',
            ], 401);
        }

        Gate::forUser($user)->authorize('viewAny', Document::class);

        $usage = $this->storageUsage->handle($user);

        return response()->json([
            'used_bytes' => $usage->usedBytes,
            'limit_bytes' => $usage->limitBytes,
            'remaining_bytes' => $usage->remainingBytes(),
            'percentage' => $usage->percentage(),
            'over_limit' => $usage->isOverLimit(),
            'used_label' => $this->bytes->format($usage->usedBytes),
            'limit_label' => $this->bytes->format($usage->limitBytes),
        ]);
    }

    /**
     * POST /api/documents
     *
     * Signature prep is never triggered here — a client-attached PDF upload
     * still needs a chance to name co-signers before an envelope can be
     * created (see PrepareEnvelopeForSignature, which takes a `signers`
     * list up front because DocuSign requires every recipient at creation
     * time). The frontend's submitUpload instead opens PrepareSignatureModal
     * right after a client-attached upload — the same "collect signers,
     * then call EnvelopeController::prepare()" flow the manual "Prepare for
     * Signature" row action already uses — rather than this controller
     * creating the envelope itself.
     */
    public function store(StoreDocumentRequest $request): DocumentResource|Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to upload documents.',
            ], 401);
        }

        $matter = $request->integer('matter_id')
            ? Matter::query()->find($request->integer('matter_id'))
            : null;

        Gate::forUser($user)->authorize('create', [Document::class, $matter]);

        // A Matter belongsTo exactly one Client (MattersRequest requires
        // client_id at matter-creation time — see .claude/rules/matter.md), so
        // once a matter is given here, its own client_id is the only
        // consistent value for the document — never the independently
        // submitted `client_id`, which the Upload Documents modal on
        // /dashboard/documents keeps in sync client-side (auto-fills and
        // locks the Client field once a matter is picked) but a stale page
        // or a non-frontend API caller could still disagree. Matches the
        // same "derive from the resolved parent, don't trust the
        // request for it" rule FolderController::store() already applies to
        // nested folders (.claude/rules/document.md). Only fall back to the
        // request's own client_id for the legitimate "no matter" case.
        $document = $this->uploadDocument->handle(DocumentData::fromRequest(
            provider_id: (int) $user->provider_id,
            uploaded_by: (int) $user->id,
            matter_id: $matter?->id,
            client_id: $matter?->client_id ?? ($request->integer('client_id') ?: null),
            folder_id: $request->integer('folder_id') ?: null,
            request: $request,
        ));

        // Loaded so DocumentResource can expose matter_public_id — the
        // upload-success modal on /dashboard/documents links straight to
        // /dashboard/matters/{public_id} from this response, see
        // .claude/rules/matter.md. Cheap here (one row); every other caller
        // of this resource already eager-loads `matter` on its own listing
        // query (EloquentDocumentRepository).
        return DocumentResource::make($document->load('matter'));
    }

    /**
     * DELETE /api/documents/{document}
     *
     * Only a `draft` document may ever be deleted — DocumentDeletionPolicy
     * (via DeleteDocumentHandler) is the actual enforcement, not the row
     * actions on /dashboard/documents hiding the Delete button for
     * non-draft rows. This route exists precisely so a stale page or a
     * replayed request for a document that has since moved past draft gets
     * a clear 422 naming why, rather than a 500 or a silent no-op — see
     * .claude/rules/document.md, "Document Deletion & Archival Rules".
     */
    public function destroy(Request $request, Document $document): Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to delete documents.',
            ], 401);
        }

        Gate::forUser($user)->authorize('delete', $document);

        try {
            $this->deleteDocument->handle($document, $user);
        } catch (DocumentCannotBeDeletedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->noContent();
    }

    /**
     * POST /api/documents/{document}/archive
     *
     * No status restriction — any document, draft through completed, may be
     * archived (ArchiveDocumentHandler). Reuses the `update` gate/permission
     * rather than a dedicated one: archiving only flips `archived_at`, the
     * same class of change `document.update` already covers.
     */
    public function archive(Request $request, Document $document): DocumentResource|Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to archive documents.',
            ], 401);
        }

        Gate::forUser($user)->authorize('update', $document);

        return DocumentResource::make($this->archiveDocument->handle($document, $user));
    }

    /**
     * POST /api/documents/{document}/unarchive
     */
    public function unarchive(Request $request, Document $document): DocumentResource|Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to unarchive documents.',
            ], 401);
        }

        Gate::forUser($user)->authorize('update', $document);

        return DocumentResource::make($this->unarchiveDocument->handle($document, $user));
    }

    /**
     * POST /api/documents/{document}/void
     *
     * The cancellation path for a `sent`/`partially_signed` document — the
     * alternative to Delete once a document has left draft. Outside those
     * two statuses VoidDocumentHandler rejects with
     * DocumentCannotBeVoidedException, translated to a 422 here for the
     * same "stale page / replayed request" reason as destroy() above.
     */
    public function void(Request $request, Document $document): DocumentResource|Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to void documents.',
            ], 401);
        }

        Gate::forUser($user)->authorize('update', $document);

        try {
            return DocumentResource::make($this->voidDocument->handle($document, $user));
        } catch (DocumentCannotBeVoidedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
