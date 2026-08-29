<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `staff_reminder_sent_at` dedups the "your client's message is still unread"
 * reminder email to exactly one send per unread episode — see
 * .claude/rules/messaging.md, "Unread-message reminder email (staff)".
 *
 * SendStaffUnreadMessageReminder sets it when it sends; MessageThread::markReadFor()
 * clears it back to null the moment the thread's own staff member reads the
 * conversation, so the next unread client message starts a fresh episode. Nullable
 * because it means "no reminder outstanding" for the vast majority of threads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->timestamp('staff_reminder_sent_at')->nullable()->after('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table) {
            $table->dropColumn('staff_reminder_sent_at');
        });
    }
};
