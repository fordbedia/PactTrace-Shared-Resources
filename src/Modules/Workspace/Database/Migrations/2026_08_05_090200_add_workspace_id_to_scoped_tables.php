<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denormalises `workspace_id` onto every table that must be scoped
 * independently.
 *
 * A document is reachable from its matter and an envelope from its document, so
 * two of these three columns are redundant in the normalised sense. That is the
 * point: with the column present on each table, isolation is a single WHERE on
 * the table being queried, and there is no join for a future query to forget.
 * A missed join is the failure mode that leaks one workspace's documents into
 * another, and it is invisible in review — a missing column is not.
 *
 * The cost is that the three columns must agree. Nothing but
 * BelongsToWorkspace's creating hook writes them, and it derives each from the
 * parent it is denormalising from, so they cannot diverge at creation. Moving a
 * matter between workspaces would need to carry its documents and envelopes
 * with it; there is no such use case today, and the write path for one belongs
 * in a use case rather than in a setter.
 *
 * The column is nullable. It has to be — these tables already hold rows from
 * before workspaces existed, and there is no correct workspace to backfill them
 * with until a provider creates one. A null workspace_id means "not yet
 * assigned", and such a row is invisible to any workspace-scoped query, which
 * is the safe direction to fail.
 */
return new class extends Migration
{
    /**
     * Tables that carry their own workspace_id, and the column each one's
     * workspace_id sits after.
     *
     * @var array<string, string>
     */
    private array $tables = [
        'matters' => 'provider_id',
        'documents' => 'provider_id',
        'envelopes' => 'provider_id',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName => $after) {
            Schema::table($tableName, function (Blueprint $table) use ($after): void {
                // constrained() creates the foreign key, and MySQL builds the
                // supporting index for it automatically — so this column is
                // indexed without a second explicit index() that would
                // duplicate it.
                $table->foreignId('workspace_id')
                    ->nullable()
                    ->after($after)
                    ->constrained('workspaces')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->tables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('workspace_id');
            });
        }
    }
};
