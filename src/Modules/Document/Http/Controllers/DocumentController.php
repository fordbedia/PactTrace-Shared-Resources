<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Http\Controllers;

use App\Http\Concerns\ResolvesActingUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PactTraceSDK\SharedResources\Modules\Document\Application\Action\ListDocumentsAction;
use PactTraceSDK\SharedResources\Modules\Document\Application\Action\UploadDocumentAction;
use PactTraceSDK\SharedResources\Modules\Document\Application\DTO\DocumentData;
use PactTraceSDK\SharedResources\Modules\Document\Application\DTO\DocumentListData;
use PactTraceSDK\SharedResources\Modules\Document\Http\Requests\StoreDocumentRequest;
use PactTraceSDK\SharedResources\Modules\Document\Http\Resources\DocumentResource;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
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
     * POST /api/documents
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

        $document = $this->uploadDocument->handle(DocumentData::fromRequest(
            provider_id: (int) $user->provider_id,
            uploaded_by: (int) $user->id,
            matter_id: $request->integer('matter_id') ?: null,
            client_id: $request->integer('client_id') ?: null,
            folder_id: $request->integer('folder_id') ?: null,
            request: $request,
        ));

        return DocumentResource::make($document);
    }
}
