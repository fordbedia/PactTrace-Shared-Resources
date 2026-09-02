<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalises `workspace_id` onto `message_threads`, the same shape the
 * 2026_08_05 "add_workspace_id_to_scoped_tables" migration used for
 * matters/documents/envelopes: nullable FK, indexed by the auto-created
 * foreign key, cascade on delete.
 *
 * Every thread has a required `matter_id`, so BelongsToWorkspace's creating
 * hook inherits the value from that matter (MessageThread::workspaceIdFromParent()).
 * Nullable because rows predate the feature — a null workspace_id means "not
 * yet assigned" and is invisible to any workspace-scoped query, which is the
 * safe direction to fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_threads', function (Blueprint $table): void {
            $table->foreignId('workspace_id')
                ->nullable()
                ->after('matter_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('message_threads', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
