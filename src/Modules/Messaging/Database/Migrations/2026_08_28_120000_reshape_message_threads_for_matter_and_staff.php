<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reshapes `message_threads` around the "one staff member, one client, one
 * matter, per thread — never a group thread" rule (see
 * .claude/rules/messaging.md):
 *
 *  - `matter_id` becomes REQUIRED and cascades on matter delete. There is
 *    no "client, no matter" case for messaging (unlike Documents — this
 *    nullability is deliberately not copied).
 *  - `staff_user_id` is ADDED and REQUIRED — the single staff member the
 *    client is conversing with. Not a "created by" audit field: it is the
 *    identity every reply is checked against (MessageThreadPolicy::reply).
 *  - `subject` becomes REQUIRED — it is what distinguishes two threads
 *    between the same staff member and client on the same matter.
 *  - A unique key on (provider_id, matter_id, staff_user_id, subject) so
 *    the same subject with the same staffer on the same matter continues
 *    one thread rather than forking a duplicate.
 *
 * Data handling for rows that predate the rule:
 *  - threads with a null `matter_id` are invalid by definition and are
 *    hard-deleted (their messages/attachments cascade);
 *  - a null/blank `subject` is backfilled to 'General';
 *  - `staff_user_id` is backfilled from the provider's own owner
 *    (`providers.owner_user_id`), and any row that still can't be resolved
 *    is deleted.
 *
 * Regenerate the test DB snapshot after pulling this — see the top-level
 * CLAUDE.md, "Unit testing".
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Blank subjects -> 'General' before the column is locked down
        //    and before the unique key (which now includes subject) exists.
        DB::table('message_threads')
            ->where(function ($q) {
                $q->whereNull('subject')->orWhere('subject', '');
            })
            ->update(['subject' => 'General']);

        // 2. Orphan (matterless) threads are invalid under the new rule.
        //    messages / message_attachments cascade via their FKs.
        DB::table('message_threads')->whereNull('matter_id')->delete();

        // 3. Add staff_user_id nullable, backfill from the provider owner,
        //    then drop any row that still can't be attributed to a staffer.
        Schema::table('message_threads', function (Blueprint $table) {
            $table->foreignId('staff_user_id')
                ->nullable()
                ->after('client_id')
                ->constrained('users')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            UPDATE message_threads mt
            JOIN providers p ON p.id = mt.provider_id
            SET mt.staff_user_id = p.owner_user_id
            WHERE mt.staff_user_id IS NULL
        SQL);

        DB::table('message_threads')->whereNull('staff_user_id')->delete();

        // 4. Lock down staff_user_id and subject. Drop/recreate the
        //    staff_user_id FK around the nullability change so the native
        //    column change can't disturb the constraint.
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropForeign(['staff_user_id']);
        });
        Schema::table('message_threads', function (Blueprint $table) {
            $table->unsignedBigInteger('staff_user_id')->nullable(false)->change();
            $table->string('subject')->nullable(false)->change();
        });
        Schema::table('message_threads', function (Blueprint $table) {
            $table->foreign('staff_user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // 5. matter_id: required, and cascade on matter delete (a thread
        //    cannot outlive its matter). Was nullable + nullOnDelete.
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropForeign(['matter_id']);
        });
        Schema::table('message_threads', function (Blueprint $table) {
            $table->unsignedBigInteger('matter_id')->nullable(false)->change();
        });
        Schema::table('message_threads', function (Blueprint $table) {
            $table->foreign('matter_id')->references('id')->on('matters')->cascadeOnDelete();
        });

        // 6. One thread per (matter, staff, subject) within a provider.
        //    client_id is derived from the matter, so it is redundant here.
        Schema::table('message_threads', function (Blueprint $table) {
            $table->unique(
                ['provider_id', 'matter_id', 'staff_user_id', 'subject'],
                'message_threads_scope_subject_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropUnique('message_threads_scope_subject_unique');
        });

        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropForeign(['staff_user_id']);
            $table->dropColumn('staff_user_id');
        });

        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropForeign(['matter_id']);
        });
        Schema::table('message_threads', function (Blueprint $table) {
            $table->unsignedBigInteger('matter_id')->nullable()->change();
            $table->string('subject')->nullable()->change();
        });
        Schema::table('message_threads', function (Blueprint $table) {
            $table->foreign('matter_id')->references('id')->on('matters')->nullOnDelete();
        });
    }
};
