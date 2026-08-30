<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the matter-level "assigned staff" point of contact — see
 * .claude/rules/matter.md, "Matter-level assigned staff".
 *
 * `assigned_staff_id` is nullable: a brand-new matter can exist with nobody
 * explicitly assigned (owner-only contact until someone assigns it), and
 * clearing the field back to null is a supported reassignment.
 *
 * `nullOnDelete()` rather than `cascadeOnDelete()`: an assigned staff member
 * being removed from the system must never take the matter's row (and its
 * whole audit trail) down with it — the matter simply falls back to
 * owner-only contact. The provider's owner is NOT stored here; it is always
 * derived from `providers.owner_user_id` (Provider::owner()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->foreignId('assigned_staff_id')
                ->nullable()
                ->after('client_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_staff_id');
        });
    }
};
