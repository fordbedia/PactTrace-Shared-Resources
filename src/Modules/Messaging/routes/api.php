<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Controllers\MessageController;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Controllers\PortalMessagingController;

/*
|--------------------------------------------------------------------------
| Messaging module API routes
|--------------------------------------------------------------------------
|
| Loaded by SharedResourceServiceProvider under the `api` middleware group
| and an `/api` prefix, so these resolve under /api/v1/*.
|
| Provider side (MessageController) is on real `auth:sanctum`. The client
| portal side (PortalMessagingController) sits OUTSIDE that group and uses
| ResolvesActingUser + Gate::forUser(), the same pattern as
| PortalMatterController — see that controller's docblock.
|
| `{matter}` binds by Matter::public_id; `{thread}` binds by
| MessageThread `id` (an archived thread is soft-deleted and won't bind).
|
*/

Route::prefix('v1')->group(function () {
    Route::middleware('auth:sanctum')->group(function () {
        // Inbox (/dashboard/messages) — All / Unread tabs, sidebar badge,
        // opening a thread, archiving, marking read.
        Route::get('/messages/threads', [MessageController::class, 'threads']);
        Route::get('/messages/unread-count', [MessageController::class, 'unreadCount']);
        Route::get('/messages/threads/{thread}', [MessageController::class, 'showThread']);
        Route::delete('/messages/threads/{thread}', [MessageController::class, 'archive']);
        Route::post('/messages/threads/{thread}/read', [MessageController::class, 'markRead']);

        // New Message modal — starts a new conversation (matter-first).
        Route::post('/messages', [MessageController::class, 'store']);

        // Reply into an existing thread — `reply` policy enforces that only
        // the thread's own staff_user_id may post from the provider side.
        Route::post('/messages/threads/{thread}', [MessageController::class, 'reply']);

        // Download one message attachment — `view` policy on its thread.
        Route::get('/messages/attachments/{attachment}', [MessageController::class, 'downloadAttachment']);

        // A matter's own flat message list (Matter Detail page).
        Route::get('/matters/{matter}/messages', [MessageController::class, 'indexForMatter']);
    });

    // Client portal messaging widget + staff contact directory.
    Route::get('/portal/matters/{matter}/message-threads', [PortalMessagingController::class, 'threads']);
    Route::get('/portal/matters/{matter}/staff-directory', [PortalMessagingController::class, 'staffDirectory']);
    Route::post('/portal/matters/{matter}/message-threads', [PortalMessagingController::class, 'startThread']);
    Route::get('/portal/message-threads/{thread}', [PortalMessagingController::class, 'showThread']);
    Route::post('/portal/message-threads/{thread}', [PortalMessagingController::class, 'reply']);
    Route::post('/portal/message-threads/{thread}/read', [PortalMessagingController::class, 'markRead']);
    Route::get('/portal/message-attachments/{attachment}', [PortalMessagingController::class, 'downloadAttachment']);
});
