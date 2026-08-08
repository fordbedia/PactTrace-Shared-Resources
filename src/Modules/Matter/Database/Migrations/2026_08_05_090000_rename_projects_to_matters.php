<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renames the `projects` table to `matters`, and every `project_id` foreign key
 * that points at it to `matter_id`.
 *
 * Done as a forward migration rather than by editing
 * `create_projects_table` in place so existing databases keep their rows —
 * the create migration stays a historical record of what was originally built.
 *
 * MySQL will not let a column that participates in a foreign key be renamed
 * while the constraint exists, so each referencing table is unwired, renamed
 * and rewired in that order. The onDelete behaviour of each constraint is
 * reproduced exactly as it was declared in the original create migrations:
 * cascade everywhere except `message_threads`, which nulls.
 */
return new class extends Migration
{
    /**
     * Tables holding a `project_id` foreign key, mapped to whether that column
     * is nullable and what it does when the parent row is deleted.
     *
     * @var array<string, array{nullable: bool, onDelete: string}>
     */
    private array $referencingTables = [
        'milestones' => ['nullable' => false, 'onDelete' => 'cascade'],
        'documents' => ['nullable' => true, 'onDelete' => 'cascade'],
        'folders' => ['nullable' => true, 'onDelete' => 'cascade'],
        'message_threads' => ['nullable' => true, 'onDelete' => 'null'],
    ];

    public function up(): void
    {
        foreach (array_keys($this->referencingTables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['project_id']);
            });
        }

        Schema::rename('projects', 'matters');

        foreach ($this->referencingTables as $tableName => $definition) {
            Schema::table($tableName, function (Blueprint $table) use ($definition): void {
                $table->renameColumn('project_id', 'matter_id');
            });

            Schema::table($tableName, function (Blueprint $table) use ($definition): void {
                $foreign = $table->foreign('matter_id')->references('id')->on('matters');

                $definition['onDelete'] === 'cascade'
                    ? $foreign->cascadeOnDelete()
                    : $foreign->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->referencingTables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['matter_id']);
            });
        }

        Schema::rename('matters', 'projects');

        foreach ($this->referencingTables as $tableName => $definition) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->renameColumn('matter_id', 'project_id');
            });

            Schema::table($tableName, function (Blueprint $table) use ($definition): void {
                $foreign = $table->foreign('project_id')->references('id')->on('projects');

                $definition['onDelete'] === 'cascade'
                    ? $foreign->cascadeOnDelete()
                    : $foreign->nullOnDelete();
            });
        }
    }
};
