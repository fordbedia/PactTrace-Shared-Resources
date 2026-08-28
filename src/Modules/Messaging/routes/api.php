<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\Messaging\Http\Controllers\MessageController;

/*
|--------------------------------------------------------------------------
| Messaging module API routes
|--------------------------------------------------------------------------
|
| Loaded by SharedResourceServiceProvider under the `api` middleware group
| and an `/api` prefix, so these resolve under /api/v1/*.
|
| Real `auth:sanctum` middleware (not the ResolvesActingUser bypass the
| older modules still use) — see MessageController's docblock. `{matter}`
| binds by Matter::public_id, matching the Matter module's own routes;
| `{thread}` binds by MessageThread `id` (the default).
|
*/

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // Inbox (/dashboard/messages) — the "All" / "Unread" tabs, the sidebar
    // badge count, opening a thread, archiving, and marking read.
    Route::get('/messages/threads', [MessageController::class, 'threads']);
    Route::get('/messages/unread-count', [MessageController::class, 'unreadCount']);
    Route::get('/messages/threads/{thread}', [MessageController::class, 'showThread']);
    Route::delete('/messages/threads/{thread}', [MessageController::class, 'archive']);
    Route::post('/messages/threads/{thread}/read', [MessageController::class, 'markRead']);

    // New Message modal / composer.
    Route::post('/messages', [MessageController::class, 'store']);

    // A matter's own message list (Matter Detail page).
    Route::get('/matters/{matter}/messages', [MessageController::class, 'indexForMatter']);
});
