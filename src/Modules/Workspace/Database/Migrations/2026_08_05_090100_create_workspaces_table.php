<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use PactTrackSDK\SharedResources\Modules\Workspace\Domain\ValueObjects\WorkspaceType;

/**
 * Workspaces: a provider's branded space, and the words it uses.
 *
 * A workspace sits *inside* a provider rather than replacing it. `provider_id`
 * remains the tenant boundary that TenantScopedPolicy enforces; `workspace_id`
 * narrows further, so a provider running both a legal and a consulting practice
 * can keep the two apart without either becoming a separate account.
 *
 * Nothing here is plan-gated. Workspaces are available on Starter, Professional
 * and Firm alike — the columns carry no plan reference for that reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();

            // The tenant this workspace belongs to. Cascades, because a
            // workspace has no meaning without its provider.
            $table->foreignId('provider_id')->constrained('providers')->cascadeOnDelete();

            // The user who administers this workspace. Distinct from
            // providers.owner_user_id: on a Firm plan with several seats, one
            // provider can have several workspaces run by different people.
            // Restricted rather than cascading — deleting a user should not
            // silently take a workspace full of documents with it.
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->string('name');

            $table->enum('workspace_type', WorkspaceType::values())
                ->default(WorkspaceType::default()->value);

            // Copied from the type's preset at creation, then freely editable.
            // Stored rather than derived so that changing a preset never
            // rewords workspaces that already exist, and so a provider can
            // depart from the preset entirely.
            $table->string('client_label');
            $table->string('engagement_label');

            $table->timestamps();

            // Soft deletes so a removed workspace can be restored with its
            // documents and signatures intact. Note this means the database's
            // ON DELETE CASCADE never fires for a soft delete — children stay
            // addressable, and are hidden by the workspace scope instead.
            $table->softDeletes();

            // A provider's workspace names should be distinguishable in a
            // switcher. Scoped to the provider, not global.
            $table->unique(['provider_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
