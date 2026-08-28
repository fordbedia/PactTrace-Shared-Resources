<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Client\Models\Client;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\ArchiveThreadAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\GetUnreadThreadCountAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\ListMatterMessagesAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\ListProviderThreadsAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\MarkThreadReadAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\SendMessageAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\SendMessageData;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\ThreadListData;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Requests\SendMessageRequest;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources\MessageResource;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources\MessageThreadResource;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;

/**
 * Inbound adapter for in-portal messaging — the /dashboard/messages inbox
 * (threads / showThread / unreadCount / archive / markRead), the New
 * Message modal (store), and a matter's message list (indexForMatter).
 *
 * On real `auth:sanctum` middleware (see routes/api.php), not the
 * ResolvesActingUser local-dev bypass the older modules still carry — this
 * is new surface built after the User module's Sanctum auth landed, so it
 * follows the modern pattern MattersController / EnvelopeDetailController
 * already use. Thin by design: validation in SendMessageRequest / DTOs,
 * orchestration in the Application\Action classes, persistence and storage
 * behind the MessageRepository / DocumentStorage ports.
 */
class MessageController extends Controller
{
    /**
     * GET /api/v1/messages/threads?filter=all|unread&page=&per_page=
     *
     * One page of the inbox for the signed-in provider. Both tabs are the
     * same query narrowed by `filter` (see ThreadListData) — "Archived" is
     * not a tab: an archived thread is soft-deleted and excluded by the
     * model's SoftDeletes trait. Returns a LengthAwarePaginator through
     * MessageThreadResource::collection(), so the response carries the
     * standard `links` / `meta` blocks, same shape as MattersController.
     */
    public function threads(Request $request, ListProviderThreadsAction $action): AnonymousResourceCollection
    {
        $user = $request->user();

        Gate::forUser($user)->authorize('viewAny', MessageThread::class);

        $data = ThreadListData::fromRequest($request, (int) $user->provider_id, (int) $user->id);

        return MessageThreadResource::collection($action->handle($data));
    }

    /**
     * GET /api/v1/messages/unread-count
     *
     * The single figure behind the "Unread" tab pill and the sidebar
     * "Messages" badge — one server-computed source of truth, so the two
     * can never disagree. Same `viewAny` gate as the list: it's the same
     * read, expressed as one aggregate.
     */
    public function unreadCount(Request $request, GetUnreadThreadCountAction $action): JsonResponse
    {
        $user = $request->user();

        Gate::forUser($user)->authorize('viewAny', MessageThread::class);

        return response()->json([
            'count' => $action->handle((int) $user->provider_id, (int) $user->id),
        ]);
    }

    /**
     * GET /api/v1/messages/threads/{thread}
     *
     * One thread with its full conversation (oldest first), for the
     * /dashboard/messages conversation pane. `{thread}` binds by `id`;
     * `view` gate applies tenant scoping. An archived thread is
     * soft-deleted and 404s here.
     */
    public function showThread(Request $request, MessageThread $thread): MessageThreadResource
    {
        Gate::forUser($request->user())->authorize('view', $thread);

        return MessageThreadResource::make(
            $thread->load(['client', 'conversation.sender', 'conversation.attachments']),
        );
    }

    /**
     * DELETE /api/v1/messages/threads/{thread}
     *
     * Archive a conversation — a soft delete. It leaves both inbox tabs
     * immediately; the row and its messages stay for the audit trail
     * (MessageThread::withTrashed()). `archive` gate = `message.send`
     * permission + tenant scoping.
     */
    public function archive(Request $request, MessageThread $thread, ArchiveThreadAction $action): Response
    {
        Gate::forUser($request->user())->authorize('archive', $thread);

        $action->handle($thread);

        return response()->noContent();
    }

    /**
     * POST /api/v1/messages/threads/{thread}/read
     *
     * Marks the thread read for the signed-in user (stamps every message
     * they did not send that has no `read_at`). This is what drops the
     * thread out of the "Unread" tab and decrements the sidebar badge.
     */
    public function markRead(Request $request, MessageThread $thread, MarkThreadReadAction $action): MessageThreadResource
    {
        $user = $request->user();

        Gate::forUser($user)->authorize('view', $thread);

        $action->handle($thread, (int) $user->id);

        return MessageThreadResource::make($thread->load(['client']));
    }

    /**
     * POST /api/v1/messages
     *
     * Starts (or continues) a conversation with a client. `client_id` is
     * the source of truth for who the thread is with; if `matter_id` is
     * also given, the matter's own client wins and is used instead — a
     * Matter belongsTo exactly one Client (see .claude/rules/matter.md), so
     * a stale page or non-frontend caller submitting a disagreeing pair is
     * reconciled here rather than trusted, the same rule
     * DocumentController::store() applies.
     */
    public function store(SendMessageRequest $request, SendMessageAction $action): MessageResource
    {
        $user = $request->user();
        $providerId = (int) $user->provider_id;

        /** @var Client $client */
        $client = Client::query()->findOrFail($request->integer('client_id'));

        // Permission (message.send) + tenant scoping on the client.
        Gate::forUser($user)->authorize('create', [MessageThread::class, $client]);

        $matterId = null;
        $clientId = $client->id;

        if ($request->filled('matter_id')) {
            /** @var Matter $matter */
            $matter = Matter::query()->findOrFail($request->integer('matter_id'));

            // Rejects a matter from another tenant (the exists: rule only
            // checks the row is real, not that it's ours).
            Gate::forUser($user)->authorize('view', $matter);

            $matterId = $matter->id;
            $clientId = $matter->client_id;
        }

        $data = SendMessageData::fromRequest(
            request: $request,
            provider_id: $providerId,
            sender_id: (int) $user->id,
            client_id: $clientId,
            matter_id: $matterId,
            attachments: array_values($request->file('attachments', [])),
        );

        return MessageResource::make($action->handle($data));
    }

    /**
     * GET /api/v1/matters/{matter}/messages
     *
     * Every message on the matter, oldest first. `{matter}` binds by
     * Matter::public_id (its default route key). Same `view` gate as the
     * Matter Detail page itself.
     */
    public function indexForMatter(
        Request $request,
        Matter $matter,
        ListMatterMessagesAction $action,
    ): AnonymousResourceCollection {
        Gate::forUser($request->user())->authorize('view', $matter);

        return MessageResource::collection(
            $action->handle((int) $matter->provider_id, (int) $matter->id),
        );
    }
}
