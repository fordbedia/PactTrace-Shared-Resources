<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archiving a conversation is a soft delete on `message_threads` — the row
 * (and its messages, via the FK) stays for the audit trail; it just drops
 * out of the inbox. See .claude/rules/messaging.md, "Archive is an action,
 * not a tab". Eloquent's SoftDeletes trait on MessageThread excludes
 * `deleted_at IS NOT NULL` from every query by default, so no scope is
 * hand-written anywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
