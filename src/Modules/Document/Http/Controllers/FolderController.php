<?php

declare(strict_types=1);

namespace PactTraceSDK\SharedResources\Modules\Document\Http\Controllers;

use App\Http\Concerns\ResolvesActingUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PactTraceSDK\SharedResources\Modules\Client\Models\Client;
use PactTraceSDK\SharedResources\Modules\Document\Application\DTO\FolderData;
use PactTraceSDK\SharedResources\Modules\Document\Application\UseCases\CreateFolder;
use PactTraceSDK\SharedResources\Modules\Document\Application\UseCases\ListFolderTree;
use PactTraceSDK\SharedResources\Modules\Document\Http\Requests\StoreFolderRequest;
use PactTraceSDK\SharedResources\Modules\Document\Http\Resources\FolderResource;
use PactTraceSDK\SharedResources\Modules\Document\Models\Folder;
use PactTraceSDK\SharedResources\Modules\Matter\Models\Matter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inbound adapter for the FOLDERS panel on /dashboard/documents (see
 * .claude/rules/document.md) — the tree there was client-state-only until
 * this controller existed. Thin by design, same shape as
 * DocumentController: validation in StoreFolderRequest, tree-shaping and
 * persistence in the UseCases, this class only authorizes and shapes the
 * response.
 *
 * NOTE: same no-auth-yet situation as DocumentController —
 * resolveActingUser() is the LOCAL-ONLY DEV_ACTING_USER_ID bypass (see
 * App\Http\Concerns\ResolvesActingUser), exercised against
 * database/seeders/DevTenantSeeder.php until real login exists.
 */
class FolderController extends Controller
{
    use ResolvesActingUser;

    public function __construct(
        private readonly CreateFolder $createFolder,
        private readonly ListFolderTree $listFolderTree,
    ) {
    }

    /**
     * GET /api/folders
     */
    public function index(Request $request): JsonResponse|Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to view folders.',
            ], 401);
        }

        Gate::forUser($user)->authorize('viewAny', Folder::class);

        return response()->json([
            'data' => $this->listFolderTree->handle((int) $user->provider_id),
        ]);
    }

    /**
     * POST /api/folders
     */
    public function store(StoreFolderRequest $request): FolderResource|Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to create folders.',
            ], 401);
        }

        // A folder nested under another folder inherits its parent's
        // client/matter scope rather than accepting its own — the "+" on
        // the tree only ever asks for a name (CreateFolderModal), and a
        // subfolder that disagreed with its parent about which client/matter
        // it belongs to would be an inconsistent tree.
        $parentFolder = $request->integer('parent_id')
            ? Folder::query()->find($request->integer('parent_id'))
            : null;

        $destination = $parentFolder
            ?? ($request->integer('matter_id') ? Matter::query()->find($request->integer('matter_id')) : null)
            ?? ($request->integer('client_id') ? Client::query()->find($request->integer('client_id')) : null);

        Gate::forUser($user)->authorize('create', [Folder::class, $destination]);

        $folder = $this->createFolder->handle(FolderData::fromRequest(
            id: null,
            provider_id: (int) $user->provider_id,
            client_id: $parentFolder?->client_id ?? ($request->integer('client_id') ?: null),
            matter_id: $parentFolder?->matter_id ?? ($request->integer('matter_id') ?: null),
            parent_id: $parentFolder?->id,
            request: $request,
        ));

        return FolderResource::make($folder);
    }

    /**
     * DELETE /api/folders/{folder}
     *
     * Wired alongside create/list rather than left client-only: once
     * folders persist, a delete that only removed the local tree node would
     * leave the row (and its subfolders/documents) alive server-side,
     * reappearing on the next page load. The FK is `cascadeOnDelete` on
     * both `folders.parent_id` and `documents.folder_id` is `nullOnDelete`
     * (see create_documents_table), so children folders are removed and
     * their documents are detached, not deleted.
     */
    public function destroy(Request $request, Folder $folder): Response
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->provider_id === null) {
            return response()->json([
                'message' => 'You must be signed in to a provider account to delete folders.',
            ], 401);
        }

        Gate::forUser($user)->authorize('delete', $folder);

        $folder->delete();

        return response()->noContent();
    }
}
