<?php

use Illuminate\Support\Facades\Route;
use PactTrackSDK\SharedResources\Modules\Document\Http\Controllers\DocumentController;
use PactTrackSDK\SharedResources\Modules\Document\Http\Controllers\FolderController;

/*
|--------------------------------------------------------------------------
| Document module API routes
|--------------------------------------------------------------------------
|
| Loaded by SharedResourceServiceProvider under the `api` middleware group
| and an `/api` prefix, so the routes below resolve to:
|
|     GET    /api/documents
|     GET    /api/documents/storage
|     POST   /api/documents
|     GET    /api/documents/{document}
|     GET    /api/documents/{document}/download
|     DELETE /api/documents/{document}
|     POST   /api/documents/{document}/archive
|     POST   /api/documents/{document}/unarchive
|     POST   /api/documents/{document}/void
|     PATCH  /api/documents/{document}/matter
|     GET    /api/folders
|     POST   /api/folders
|     DELETE /api/folders/{folder}
|
| No auth middleware yet — see the note in DocumentController /
| FolderController. Add the appropriate guard middleware here once auth
| scaffolding exists.
*/

Route::get('documents', [DocumentController::class, 'index']);
// Before any future `documents/{document}` route, so "storage" is never
// swallowed as a document id. Same reasoning for the three bulk routes
// below (move-many/archive-many/zip) — none of them is a numeric id, but
// they still need to come first for the same "never swallowed by
// {document}" reason.
Route::get('documents/storage', [DocumentController::class, 'storage']);
Route::post('documents/move-many', [DocumentController::class, 'moveMany']);
Route::post('documents/archive-many', [DocumentController::class, 'archiveMany']);
Route::post('documents/zip', [DocumentController::class, 'zip']);
Route::post('documents', [DocumentController::class, 'store']);
// The Document Detail page's fetch — see .claude/rules/document.md,
// "Document Detail is a real route". Must come after `documents/storage`
// above for the same reason that route's own comment gives.
Route::get('documents/{document}', [DocumentController::class, 'show']);
Route::get('documents/{document}/download', [DocumentController::class, 'download']);
// See .claude/rules/document.md, "Document Deletion & Archival Rules" — the
// controller only authorizes and translates domain exceptions to HTTP
// status; the actual policy checks live in DeleteDocumentHandler /
// ArchiveDocumentHandler / UnarchiveDocumentHandler / VoidDocumentHandler.
Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
Route::post('documents/{document}/archive', [DocumentController::class, 'archive']);
Route::post('documents/{document}/unarchive', [DocumentController::class, 'unarchive']);
Route::post('documents/{document}/void', [DocumentController::class, 'void']);
// The Documents page's per-row "Reassign Matter" action (the pen icon) —
// see .claude/rules/document.md, "Reassign Matter from the Documents page".
Route::patch('documents/{document}/matter', [DocumentController::class, 'reassignMatter']);

Route::get('folders', [FolderController::class, 'index']);
Route::post('folders', [FolderController::class, 'store']);
Route::delete('folders/{folder}', [FolderController::class, 'destroy']);
