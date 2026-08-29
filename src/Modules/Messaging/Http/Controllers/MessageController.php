<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\ArchiveThreadAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\DownloadMessageAttachment;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\GetUnreadThreadCountAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\ListMatterMessagesAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\ListProviderThreadsAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\MarkThreadReadAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\ReplyToThreadAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\SendMessageAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\ReplyMessageData;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\SendMessageData;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\ThreadListData;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Requests\ReplyMessageRequest;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Requests\SendMessageRequest;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources\MessageResource;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources\MessageThreadResource;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageAttachment;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inbound adapter for the provider-side /dashboard/messages inbox — the
 * All/Unread tabs, the sidebar badge, opening a thread, archiving, marking
 * read, the New Message modal (store), and replying into a thread (reply).
 *
 * On real `auth:sanctum` middleware (see routes/api.php), not the
 * ResolvesActingUser local-dev bypass the older modules still carry — this
 * follows the modern MattersController / EnvelopeDetailController pattern.
 * The client-portal side of messaging is a separate adapter
 * (PortalMessagingController), same split as PortalMatterController vs
 * MattersController. Thin by design: validation in the FormRequests / DTOs,
 * orchestration in the Application\Action classes.
 */
class MessageController extends Controller
{
    /**
     * GET /api/v1/messages/threads?filter=all|unread&page=&per_page=
     *
     * One page of the inbox for the signed-in provider. Both tabs are the
     * same query narrowed by `filter` (see ThreadListData). Returns a
     * LengthAwarePaginator through MessageThreadResource::collection(), so
     * the response carries the standard `links` / `meta` blocks, same
     * shape as MattersController.
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
     * "Messages" badge — one server-computed source of truth.
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
     * /dashboard/messages conversation pane. `view` gate applies tenant
     * scoping — any staff user in the provider may read it (the
     * audit-trail/continuity guarantee); an archived thread is
     * soft-deleted and 404s here.
     */
    public function showThread(Request $request, MessageThread $thread): MessageThreadResource
    {
        Gate::forUser($request->user())->authorize('view', $thread);

        return MessageThreadResource::make(
            $thread->load(['client', 'staffMember', 'matter', 'conversation.sender', 'conversation.attachments']),
        );
    }

    /**
     * DELETE /api/v1/messages/threads/{thread}
     *
     * Archive a conversation — a soft delete. `archive` gate = `message.send`
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
     * Marks the thread read for the signed-in user. `view` gate.
     */
    public function markRead(Request $request, MessageThread $thread, MarkThreadReadAction $action): MessageThreadResource
    {
        $user = $request->user();

        Gate::forUser($user)->authorize('view', $thread);

        $action->handle($thread, (int) $user->id);

        return MessageThreadResource::make($thread->load(['client', 'staffMember']));
    }

    /**
     * POST /api/v1/messages
     *
     * Starts a NEW conversation from the staff New Message modal. Matter
     * first: `matter_id` is required, the client is the matter's own
     * client (never a submitted `client_id`), the staff party is the
     * authenticated user, and `subject` is required. Re-using an identical
     * subject on the same matter continues that thread rather than forking
     * a duplicate (SendMessageAction / the DB unique key).
     */
    public function store(SendMessageRequest $request, SendMessageAction $action): MessageResource
    {
        $user = $request->user();

        /** @var Matter $matter */
        $matter = Matter::query()->findOrFail($request->integer('matter_id'));

        // Tenant scoping (rejects another provider's matter the `exists`
        // rule alone would allow) + `message.send` against the matter's
        // client.
        Gate::forUser($user)->authorize('view', $matter);
        Gate::forUser($user)->authorize('create', [MessageThread::class, $matter->client]);

        $data = new SendMessageData(
            provider_id: (int) $matter->provider_id,
            sender_id: (int) $user->id,
            staff_user_id: (int) $user->id,
            client_id: (int) $matter->client_id,
            matter_id: (int) $matter->id,
            subject: trim((string) $request->input('subject')),
            body: trim((string) $request->input('body')),
            attachments: array_values($request->file('attachments', [])),
        );

        return MessageResource::make($action->handle($data));
    }

    /**
     * POST /api/v1/messages/threads/{thread}
     *
     * Replies into an existing thread. `reply` gate enforces the
     * one-staff-member-per-thread rule: only the thread's own
     * `staff_user_id` may post from the provider side — a different
     * staffer viewing the same thread is read-only.
     */
    public function reply(
        ReplyMessageRequest $request,
        MessageThread $thread,
        ReplyToThreadAction $action,
    ): MessageResource {
        $user = $request->user();

        Gate::forUser($user)->authorize('reply', $thread);

        $data = new ReplyMessageData(
            sender_id: (int) $user->id,
            body: trim((string) $request->input('body')),
            attachments: array_values($request->file('attachments', [])),
        );

        return MessageResource::make($action->handle($thread, $data));
    }

    /**
     * GET /api/v1/matters/{matter}/messages
     *
     * Every message on the matter, oldest first. `{matter}` binds by
     * Matter::public_id. Same `view` gate as the Matter Detail page.
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

    /**
     * GET /api/v1/messages/attachments/{attachment}
     *
     * Serves one message attachment's bytes. Authorised by
     * MessageThreadPolicy::view on the attachment's own thread — the same
     * gate as reading the conversation, so any staffer in the provider can
     * fetch it and a member of another tenant cannot. Served `inline` so
     * images/PDFs open in a tab; other types download.
     */
    public function downloadAttachment(
        Request $request,
        MessageAttachment $attachment,
        DownloadMessageAttachment $action,
    ): StreamedResponse {
        $attachment->loadMissing('message.thread');
        $thread = $attachment->message?->thread;

        abort_if($thread === null, 404);

        Gate::forUser($request->user())->authorize('view', $thread);

        $file = $action->handle($attachment);

        return response()->streamDownload(
            static function () use ($file): void {
                echo $file->contents;
            },
            $file->fileName,
            ['Content-Type' => $file->mimeType],
            'inline',
        );
    }
}
