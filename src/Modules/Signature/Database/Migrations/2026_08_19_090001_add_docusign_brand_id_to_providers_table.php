<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's DocuSign Signing Brand id — applied to their envelopes so
 * signing emails/pages carry their own logo/colors instead of PactTrack's
 * default. Nullable and unmanaged by any admin UI yet: set manually per
 * tenant until brand management is built. Null is a normal, expected state
 * (Starter-tier tenants use PactTrack's own DOCUSIGN_DEFAULT_BRAND_ID
 * instead; see Application/Services/ResolveEnvelopeBrand and
 * .claude/rules/signature.md) — DocusignSignatureProvider::applyBrand()
 * treats a null brandId as a no-op, not an error.
 *
 * Lives on `providers` (owned by the User module) rather than in the
 * Signature module's own tables, following the precedent set by the
 * Workspace module's `add_workspace_id_to_scoped_tables` migration
 * (feature-owning module adds the column to the table it actually
 * belongs on, cross-module).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('docusign_brand_id')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn('docusign_brand_id');
        });
    }
};
