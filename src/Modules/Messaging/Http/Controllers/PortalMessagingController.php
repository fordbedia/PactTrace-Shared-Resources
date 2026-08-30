<?php

declare(strict_types=1);

namespace PactTrackSDK\SharedResources\Modules\Messaging\Http\Controllers;

use App\Http\Concerns\ResolvesActingUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use PactTrackSDK\SharedResources\Modules\Matter\Models\Matter;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\DownloadMessageAttachment;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\GetMatterContactDirectory;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\ListMatterThreadsAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\MarkThreadReadAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\ReplyToThreadAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\Action\SendMessageAction;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\ReplyMessageData;
use PactTrackSDK\SharedResources\Modules\Messaging\Application\DTO\SendMessageData;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Requests\ReplyMessageRequest;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Requests\StartPortalThreadRequest;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources\MessageResource;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources\MessageThreadResource;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Resources\PortalStaffResource;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageAttachment;
use PactTrackSDK\SharedResources\Modules\Messaging\Models\MessageThread;
use PactTrackSDK\SharedResources\Modules\User\Domain\ValueObjects\Role;
use PactTrackSDK\SharedResources\Modules\User\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inbound adapter for the client-portal messaging widget on
 * `/portal/matter/{public_id}` and its staff contact directory. Kept
 * separate from MessageController for the same reasons PortalMatterController
 * is kept separate from MattersController (see that class): a different
 * audience (a client), a narrower payload, and the `ResolvesActingUser` +
 * `Gate::forUser()` auth pattern the rest of the portal surface uses rather
 * than `auth:sanctum` route middleware.
 *
 * `{matter}` binds by Matter::public_id (its route key); `{thread}` binds
 * by MessageThread id. Every thread action reuses the SAME Application
 * use-cases the staff side calls — SendMessageAction / ReplyToThreadAction
 * / MarkThreadReadAction — and the SAME MessageThreadPolicy (view/reply),
 * so the two entry points can never drift.
 */
class PortalMessagingController extends Controller
{
    use ResolvesActingUser;

    /**
     * GET /api/v1/portal/matters/{matter}/message-threads
     *
     * Every non-archived thread on this matter — a matter has exactly one
     * client, so this is already "the client's threads". Rows carry the
     * staff member, a preview and the client's unread flag.
     */
    public function threads(
        Request $request,
        Matter $matter,
        ListMatterThreadsAction $action,
    ): AnonymousResourceCollection|JsonResponse {
        $user = $this->requireClient($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        Gate::forUser($user)->authorize('view', $matter);

        return MessageThreadResource::collection(
            $action->handle((int) $matter->provider_id, (int) $matter->id, (int) $user->id),
        );
    }

    /**
     * GET /api/v1/portal/matters/{matter}/staff-directory
     *
     * The curated contact set for THIS matter — the provider's owner
     * (always, as the fallback contact) plus the matter's assigned staff
     * member when one is set, de-duplicated. Not the old flat "every
     * provider-side user" roster. Provider/matter resolved from the route,
     * never from client input. See GetMatterContactDirectory and
     * .claude/rules/matter.md, "Matter-level assigned staff".
     */
    public function staffDirectory(
        Request $request,
        Matter $matter,
        GetMatterContactDirectory $action,
    ): AnonymousResourceCollection|JsonResponse {
        $user = $this->requireClient($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        Gate::forUser($user)->authorize('view', $matter);

        $matter->loadMissing(['provider.owner', 'assignedStaff']);

        return PortalStaffResource::collection($action->handle($matter));
    }

    /**
     * GET /api/v1/portal/message-threads/{thread}
     *
     * One thread with its conversation, for the widget's right pane. The
     * `view` policy confines a client to their own threads.
     */
    public function showThread(Request $request, MessageThread $thread): MessageThreadResource|JsonResponse
    {
        $user = $this->requireClient($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        Gate::forUser($user)->authorize('view', $thread);

        return MessageThreadResource::make(
            $thread->load(['staffMember', 'matter', 'conversation.sender', 'conversation.attachments']),
        );
    }

    /**
     * POST /api/v1/portal/matters/{matter}/message-threads
     *
     * The client starts a conversation with a chosen staff member. The
     * matter (hence provider + client) is implicit from the route; only
     * `staff_user_id`, `subject` and the message come from the body.
     * Reuses SendMessageAction — no parallel creation path.
     */
    public function startThread(
        StartPortalThreadRequest $request,
        Matter $matter,
        SendMessageAction $action,
    ): MessageResource|JsonResponse {
        $user = $this->requireClient($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        Gate::forUser($user)->authorize('view', $matter);
        Gate::forUser($user)->authorize('create', [MessageThread::class, $matter->client]);

        $staff = $this->resolveProviderStaff(
            (int) $request->integer('staff_user_id'),
            (int) $matter->provider_id,
        );

        $data = new SendMessageData(
            provider_id: (int) $matter->provider_id,
            sender_id: (int) $user->id,
            staff_user_id: $staff->id,
            client_id: (int) $matter->client_id,
            matter_id: (int) $matter->id,
            subject: trim((string) $request->input('subject')),
            body: trim((string) $request->input('body')),
            attachments: array_values($request->file('attachments', [])),
        );

        return MessageResource::make($action->handle($data));
    }

    /**
     * POST /api/v1/portal/message-threads/{thread}
     *
     * The client replies into their own thread. `reply` policy authorises a
     * client-role user into any thread that is theirs (client_id scoping).
     */
    public function reply(
        ReplyMessageRequest $request,
        MessageThread $thread,
        ReplyToThreadAction $action,
    ): MessageResource|JsonResponse {
        $user = $this->requireClient($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        Gate::forUser($user)->authorize('reply', $thread);

        $data = new ReplyMessageData(
            sender_id: (int) $user->id,
            body: trim((string) $request->input('body')),
            attachments: array_values($request->file('attachments', [])),
        );

        return MessageResource::make($action->handle($thread, $data));
    }

    /**
     * POST /api/v1/portal/message-threads/{thread}/read
     *
     * Drops the thread out of the client's unread state.
     */
    public function markRead(
        Request $request,
        MessageThread $thread,
        MarkThreadReadAction $action,
    ): MessageThreadResource|JsonResponse {
        $user = $this->requireClient($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        Gate::forUser($user)->authorize('view', $thread);

        $action->handle($thread, (int) $user->id);

        return MessageThreadResource::make($thread->load(['staffMember']));
    }

    /**
     * GET /api/v1/portal/message-attachments/{attachment}
     *
     * Serves one message attachment's bytes to the portal client. The
     * `view` policy on the attachment's thread confines a client to files
     * on their own conversations — a guessed id from another client's (or
     * another tenant's) thread is denied by the same client_id/tenant
     * scoping the conversation itself sits behind. Served `inline`.
     */
    public function downloadAttachment(
        Request $request,
        MessageAttachment $attachment,
        DownloadMessageAttachment $action,
    ): StreamedResponse|JsonResponse {
        $user = $this->requireClient($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $attachment->loadMissing('message.thread');
        $thread = $attachment->message?->thread;

        abort_if($thread === null, 404);

        Gate::forUser($user)->authorize('view', $thread);

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

    /**
     * The authenticated portal client, or a 401 JsonResponse — same shape
     * as PortalMatterController.
     */
    private function requireClient(Request $request): User|JsonResponse
    {
        $user = $this->resolveActingUser($request);

        if ($user === null || $user->client === null) {
            return response()->json([
                'message' => 'You must be signed in as a client to use portal messaging.',
            ], 401);
        }

        return $user;
    }

    /**
     * Resolve `staff_user_id` to a provider-side user of THIS provider, or
     * reject. The `exists:users,id` rule only proves the row is real — a
     * client must never open a thread with another tenant's staffer, or
     * with a person who isn't provider-side at all.
     */
    private function resolveProviderStaff(int $staffUserId, int $providerId): User
    {
        $staff = User::query()
            ->where('id', $staffUserId)
            ->where('provider_id', $providerId)
            ->role(array_map(static fn (Role $r): string => $r->value, Role::providerSide()))
            ->first();

        if ($staff === null) {
            throw ValidationException::withMessages([
                'staff_user_id' => ['That team member is not available on this matter.'],
            ]);
        }

        return $staff;
    }
}
