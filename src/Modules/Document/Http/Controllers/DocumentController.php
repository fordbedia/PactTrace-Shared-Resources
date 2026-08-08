<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Http\Controllers;

use App\Http\Concerns\ResolvesActingUser;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PactTraceSDK\SharedResources\Modules\Document\Application\UseCases\UploadDocument;
use PactTraceSDK\SharedResources\Modules\Document\Http\Requests\StoreDocumentRequest;
use PactTraceSDK\SharedResources\Modules\Document\Http\Resources\DocumentResource;
use PactTraceSDK\SharedResources\Modules\Document\Models\Document;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound adapter for the "Upload Documents" modal on
 * /dashboard/documents. Thin by design — validation lives in
 * StoreDocumentRequest, storage + persistence in UploadDocument; this class
 * only authorizes and shapes the response.
 *
 * NOTE: like EnvelopeController, this has no auth middleware yet because
 * the backend has no auth scaffolding at all (see top-level CLAUDE.md,
 * "Current backend status"). `documents.uploaded_by` and
 * `documents.provider_id` are both NOT NULL foreign keys, so this endpoint
 * cannot succeed at all without a real user — `resolveActingUser()` (see
 * App\Http\Concerns\ResolvesActingUser) is a LOCAL-ONLY bypass that
 * resolves DEV_ACTING_USER_ID instead, so this can actually be exercised
 * against database/seeders/DevTenantSeeder.php before login exists.
 */
class DocumentController extends Controller
{
    use ResolvesActingUser;

    public function __construct(
        private readonly UploadDocument $uploadDocument,
    ) {
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

        $document = $this->uploadDocument->handle(
            file: $request->file('file'),
            providerId: (int) $user->provider_id,
            uploadedBy: (int) $user->id,
            matterId: $request->integer('matter_id') ?: null,
            clientId: $request->integer('client_id') ?: null,
            folderId: $request->integer('folder_id') ?: null,
        );

        return DocumentResource::make($document);
    }
}
