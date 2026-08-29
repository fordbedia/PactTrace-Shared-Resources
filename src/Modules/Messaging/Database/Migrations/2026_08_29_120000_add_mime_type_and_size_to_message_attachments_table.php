<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `mime_type` + `size` on message attachments — needed so the new
 * attachment-download endpoints can serve the file with a correct
 * Content-Type (images/PDFs open inline instead of downloading as
 * octet-stream) and so MessageAttachmentResource can report a size and pick
 * a sensible file-type icon. Both nullable: rows created before this
 * migration have neither, and an attachment that only references a Document
 * (`document_id`, no `s3_path`) carries its metadata on the Document.
 *
 * See .claude/rules/messaging.md, "Attachments — up to 5 files, 5 MB each".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->string('mime_type')->nullable()->after('file_name');
            $table->unsignedBigInteger('size')->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table) {
            $table->dropColumn(['mime_type', 'size']);
        });
    }
};
