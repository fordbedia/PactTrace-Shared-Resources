<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverts 2026_08_15_000000_add_workspace_id_to_clients_table.
 *
 * A `Client` is a provider-scoped CRM record, not a workspace-scoped one: one
 * client can have matters in several of a provider's workspaces, and the
 * /dashboard/clients roster must list every client of the tenant regardless of
 * the active workspace. The 2026-08-15 change gave `Client` the
 * `BelongsToWorkspace` global scope via a denormalised `clients.workspace_id`,
 * which meant any client row with a null `workspace_id` (the normal state — the
 * invite flow sets no workspace) became invisible to a provider that had an
 * active workspace. Reported live: a client showed for an admin whose session
 * had no workspace context but not for the owner who had switched into a
 * workspace.
 *
 * This drops `workspace_id` and restores the original
 * `unique(provider_id, email)`. Same index-ordering constraint as the original
 * migration: the composite unique is what supports the `provider_id` foreign
 * key, so the two-column unique must be recreated before the composite is
 * dropped (MySQL errno 1553 otherwise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->unique(['provider_id', 'email']);
            $table->dropUnique(['provider_id', 'workspace_id', 'email']);
            $table->dropConstrainedForeignId('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->foreignId('workspace_id')
                ->nullable()
                ->after('provider_id')
                ->constrained('workspaces')
                ->cascadeOnDelete();

            $table->unique(['provider_id', 'workspace_id', 'email']);
            $table->dropUnique(['provider_id', 'email']);
        });
    }
};
