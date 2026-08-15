<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Brings `clients` in line with `matters`/`documents`/`envelopes`: a nullable,
 * denormalised `workspace_id` so `BelongsToWorkspace`'s global scope can
 * narrow the clients list to the active workspace the same way it already
 * narrows everything else.
 *
 * Nullable for the same reason as the other three tables (see
 * add_workspace_id_to_scoped_tables.php in the Workspace module): every
 * existing client predates this column, and there is no correct workspace to
 * backfill it with until each provider actually has one. A null workspace_id
 * is invisible to a workspace-scoped query, which is the safe failure
 * direction — not a data-loss risk, since WorkspaceScope fails *open* (adds no
 * constraint) for a provider with zero workspaces. See WorkspaceScope's own
 * docblock.
 *
 * The unique index moves from (provider_id, email) to
 * (provider_id, workspace_id, email) so the same person can be a client in two
 * different workspaces of the same provider without colliding. Caveat carried
 * over from the rest of the schema: MySQL treats each NULL as distinct in a
 * unique index, so two clients with the same email and a null workspace_id
 * will not collide either — acceptable for now since nothing yet gives an
 * existing (pre-workspace) provider a way to create a second workspace, but
 * worth tightening once that exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->foreignId('workspace_id')
                ->nullable()
                ->after('provider_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();

            // The new composite unique must be created before the old one is
            // dropped: `clients_provider_id_email_unique` is the implicit
            // index MySQL is using to support the `provider_id` foreign key
            // (no separate index on `provider_id` alone was ever created), so
            // dropping it first leaves that FK without a supporting index and
            // MySQL refuses with error 1553.
            $table->unique(['provider_id', 'workspace_id', 'email']);
            $table->dropUnique(['provider_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            // Same ordering constraint as up(), in reverse.
            $table->unique(['provider_id', 'email']);
            $table->dropUnique(['provider_id', 'workspace_id', 'email']);
            $table->dropConstrainedForeignId('workspace_id');
        });
    }
};
