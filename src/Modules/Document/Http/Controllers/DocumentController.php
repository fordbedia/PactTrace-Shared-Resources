<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Document\Http\Controllers;

use App\Http\Concerns\EnforcesPlanGate;
use App\Http\Concerns\ResolvesActingUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Document\Application\Action\BuildDocumentsZipAction;
use PactTrackSDK\SharedResources\Modules\Document\Application\Action\DownloadDocumentAction;
use PactTrackSDK\SharedResources\Modules\Document\Application\Action\GetStorageUsageAction;
use PactTrackSDK\SharedResources\Modules\Document\Application\Action\ListDocumentsAction;
use PactTrackSDK\SharedResources\Modules\Document\Application\Action\UploadDocumentAction;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\ArchiveDocumentHandler;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\BulkArchiveDocumentsHandler;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\DeleteDocumentHandler;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\MoveDocumentsHandler;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\ReassignDocumentMatterHandler;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\UnarchiveDocumentHandler;
use PactTrackSDK\SharedResources\Modules\Document\Application\UseCases\VoidDocumentHandler;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Exceptions\DocumentCannotBeDeletedException;
use PactTrackSDK\SharedResources\Modules\Document\Domain\Exceptions\DocumentCannotBeVoidedException;
use PactTrackSDK\SharedResources\Modules\Document\Infrastructure\Services\ByteFormatter;
use PactTrackSDK\SharedResources\Modules\Document\Application\DTO\DocumentData;
use PactTrackSDK\SharedResources\Modules\Document\Application\DTO\DocumentListData;
use PactTrackSDK\SharedResources\Modules\Document\Http\Requests\BulkDocumentIdsRequest;
use PactTrackSDK\SharedResources\Modules\Document\Http\Requests\MoveDocumentsRequest;
use PactTrackSDK\SharedResources\Modules\Document\Http\Requests\ReassignDocumentMatterRequest;
use PactTrackSDK\SharedResources\Modules\Document\Http\Requests\StoreDocumentRequest;
use PactTrackSDK\SharedResources\Modules\Document\Http\Resources\DocumentResource;
use PactTrackSDK\SharedResources\Modules\Document\Models\Document;
use PactTrackSDK\SharedResources\Modules\Document\Models\Folder;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\GatedAction;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    use EnforcesPlanGate;

    public function __construct(
        private readonly UploadDocumentAction $uploadDocument,
        private readonly ListDocumentsAction $listDocuments,
        private readonly GetStorageUsageAction $storageUsage,
        private readonly ByteFormatter $bytes,
        private readonly DeleteDocumentHandler $deleteDocument,
        private readonly ArchiveDocumentHandler $archiveDocument,
        private readonly UnarchiveDocumentHandler $unarchiveDocument,
        private readonly VoidDocumentHandler $voidDocument,
        private readonly DownloadDocumentAction $downloadDocument,
        private readonly MoveDocumentsHandler $moveDocuments,
        private readonly BulkArchiveDocumentsHandler $bulkArchiveDocuments,
        private readonly BuildDocumentsZipAction $buildDocumentsZip,
        private readonly ReassignDocumentMatterHandler $reassignDocumentMatter,
    ) {
    }

    /**
     * Loads `$ids` scoped to the acting user's own tenant and authorizes
     * every one against `$ability` (same gate its single-row action already
     * requires) before any bulk action touches them — one unauthorized or
     * cross-tenant id in the batch fails the whole request rather than
     * silently skipping it. Shared by moveMany()/archiveMany()/zip() so the
     * three bulk actions can never disagree on this check.
     *
     * @param list<int> $ids
     */
    private function authorizedDocuments(Request $request, array $ids, string $ability): Collection
    {
        $user = $this->resolveActingUser($request);
        $documents = Document::query()->whereIn('id', $ids)->get();

        foreach ($documents as $document) {
            Gate::forUser($user)->authorize($ability, $document);
        }

        return $documents;
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

        if ($response = $this->denyIfPlanGateFails(GatedAction::UploadDocument, $user)) {
            return $response;
        }

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
     * GET /api/documents/{document}
     *
     * The Document Detail page's fetch (`/dashboard/documents/{document}` —
     * see .claude/rules/document.md, "Document Detail is a real route").
     * Mirrors MattersController::show(): a plain `view` gate, eager-loading
     * everything the resource can expose so a direct load/refresh needs only
     * this one request. `envelopes` is loaded (not just `matter`/`client`/
     * `uploader`) specifically so `envelope_public_id`/`envelope_status` are
     * populated — the single-document detail view is exactly where those two
     * fields matter most.
     */
    public function show(Request $request, Document $document): DocumentResource|Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to view this document.',
            ], 401);
        }

        Gate::forUser($user)->authorize('view', $document);

        return DocumentResource::make($document->load(['matter', 'client', 'uploader', 'envelopes']));
    }

    /**
     * GET /api/documents/{document}/download
     *
     * `download` is a distinct permission/gate from `view` — see
     * DocumentPolicy::download's own docblock and
     * .claude/rules/document.md, "Document download". No status
     * restriction: a completed/signed document is exactly the case this is
     * for.
     */
    public function download(Request $request, Document $document): RedirectResponse|StreamedResponse|Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to download documents.',
            ], 401);
        }

        Gate::forUser($user)->authorize('download', $document);

        $download = $this->downloadDocument->handle($document, $user);

        if ($download->isRedirect()) {
            return redirect()->away($download->url);
        }

        return response()->streamDownload(
            function () use ($download): void {
                echo $download->content ?? '';
            },
            $download->fileName,
            array_filter(['Content-Type' => $download->mimeType]),
        );
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

    /**
     * PATCH /api/documents/{document}/matter
     *
     * The Documents page's per-row "Reassign Matter" action (the pen icon —
     * see .claude/rules/document.md, "Reassign Matter from the Documents
     * page"). Changes only which Matter this one document is filed under;
     * it is not a Matter-entity edit (that stays exclusively on the Matter
     * module's own screens). Reuses the `update` gate, same as
     * archive/unarchive/void above — this is the same class of
     * document-field change those already cover.
     *
     * `matter_id` is resolved inside the acting provider itself, the same
     * "the request rule can't express tenancy, the controller does"
     * pattern moveMany() already applies to its own `folder_id` — a bare
     * `exists:matters,id` says nothing about whose matter it is.
     */
    public function reassignMatter(ReassignDocumentMatterRequest $request, Document $document): DocumentResource|Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to reassign documents.',
            ], 401);
        }

        Gate::forUser($user)->authorize('update', $document);

        $matter = null;

        if ($request->filled('matter_id')) {
            // acrossWorkspaces(): the destination matter may sit in a
            // workspace other than whichever one is ambient for this
            // request — see ReassignDocumentMatterHandler's own docblock
            // for why the document's workspace_id is resynced from it.
            $matter = Matter::query()
                ->acrossWorkspaces()
                ->where('provider_id', $user->provider_id)
                ->find($request->integer('matter_id'));

            if ($matter === null) {
                return response()->json(['message' => 'That matter could not be found.'], 422);
            }
        }

        $document = $this->reassignDocumentMatter->handle($document, $matter, $user);

        return DocumentResource::make($document->load(['matter', 'client']));
    }

    /**
     * POST /api/documents/move-many
     *
     * The Documents page's bulk bar "Move" action (Move modal — see
     * .claude/rules/document.md). Reuses the same `update` gate the
     * single-row Archive/Unarchive actions already require.
     */
    public function moveMany(MoveDocumentsRequest $request): AnonymousResourceCollection|Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to move documents.',
            ], 401);
        }

        $folder = Folder::query()
            ->where('provider_id', $user->provider_id)
            ->find($request->integer('folder_id'));

        if ($folder === null) {
            return response()->json(['message' => 'That folder could not be found.'], 422);
        }

        $documents = $this->authorizedDocuments($request, $request->input('document_ids'), 'update');

        return DocumentResource::collection($this->moveDocuments->handle($documents, $folder->id, $user));
    }

    /**
     * POST /api/documents/archive-many
     *
     * The Documents page's bulk bar "Archive" action (renamed from the old
     * decorative "Delete" button — Archive is the only user-facing bulk
     * removal action, see .claude/rules/document.md).
     */
    public function archiveMany(BulkDocumentIdsRequest $request): AnonymousResourceCollection|Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to archive documents.',
            ], 401);
        }

        $documents = $this->authorizedDocuments($request, $request->input('document_ids'), 'update');

        return DocumentResource::collection($this->bulkArchiveDocuments->handle($documents, $user));
    }

    /**
     * POST /api/documents/zip
     *
     * The Documents page's bulk bar "Zip" action — streams a .zip of every
     * selected document back and deletes the temp file once the response
     * finishes sending (register_shutdown_function/`deleteFileAfterSend`
     * would be swallowed by StreamedResponse's own callback, so this uses
     * that directly).
     */
    public function zip(BulkDocumentIdsRequest $request): Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to download documents.',
            ], 401);
        }

        $documents = $this->authorizedDocuments($request, $request->input('document_ids'), 'download');

        $zipPath = $this->buildDocumentsZip->handle($documents, $user);

        // A real file response (not streamDownload's closure form) so
        // `deleteFileAfterSend` can clean up the temp zip once it's fully
        // sent, success or client-abort alike.
        return response()
            ->download($zipPath, 'documents.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}
