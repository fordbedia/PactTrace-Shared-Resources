<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a persisted, queryable `file_type` bucket to `documents` — the File
 * Type filter on /dashboard/documents (see .claude/rules/document.md)
 * previously had nothing to filter on beyond a fragile `LIKE '%.pdf'` on
 * `name` at read time. `UploadDocumentAction` now sets this at upload time
 * via `Domain\Services\DocumentFileType::fromFileName()` — the same 8
 * buckets the frontend's `extToType()` (frontend/app/dashboard/documents/
 * shared.js) independently implements, so a document's server-side type and
 * its client-side badge can never disagree.
 *
 * Existing rows are backfilled here, one UPDATE per bucket (same
 * derive-from-`name` rule as `DocumentFileType::fromFileName()`), rather
 * than a per-row PHP loop — `documents.name` is the only signal available at
 * migration time, same as `DocumentFileType` itself. Anything left unmatched
 * (no recognised extension) falls into `doc`, mirroring
 * `DocumentFileType::fromFileName()`'s own default arm.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('file_type')->nullable()->after('mime_type')->index();
        });

        DB::table('documents')->whereRaw('LOWER(name) LIKE ?', ['%.pdf'])->update(['file_type' => 'pdf']);

        DB::table('documents')
            ->where(function ($query) {
                foreach (['doc', 'docm', 'docx', 'dot', 'dotm', 'dotx'] as $ext) {
                    $query->orWhereRaw('LOWER(name) LIKE ?', ["%.{$ext}"]);
                }
            })
            ->update(['file_type' => 'doc']);

        DB::table('documents')
            ->where(function ($query) {
                foreach (['htm', 'html', 'xhtml'] as $ext) {
                    $query->orWhereRaw('LOWER(name) LIKE ?', ["%.{$ext}"]);
                }
            })
            ->update(['file_type' => 'html']);

        DB::table('documents')->whereRaw('LOWER(name) LIKE ?', ['%.msg'])->update(['file_type' => 'msg']);
        DB::table('documents')->whereRaw('LOWER(name) LIKE ?', ['%.rtf'])->update(['file_type' => 'rtf']);
        DB::table('documents')->whereRaw('LOWER(name) LIKE ?', ['%.txt'])->update(['file_type' => 'txt']);
        DB::table('documents')->whereRaw('LOWER(name) LIKE ?', ['%.wpd'])->update(['file_type' => 'wpd']);
        DB::table('documents')->whereRaw('LOWER(name) LIKE ?', ['%.xps'])->update(['file_type' => 'xps']);

        // Catch-all: anything left with no recognised extension defaults to
        // `doc`, same as DocumentFileType::fromFileName()'s default arm.
        DB::table('documents')->whereNull('file_type')->update(['file_type' => 'doc']);
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('file_type');
        });
    }
};
