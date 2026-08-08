<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles moved to spatie/laravel-permission (see create_permission_tables).
 *
 * The `role` enum added alongside `provider_id` is now redundant: keeping both
 * would give us two sources of truth for the same concept, which drift apart the
 * first time a role is assigned through one and read through the other.
 * spatie is authoritative — check roles via `$user->hasRole(...)`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['owner', 'staff', 'client'])->default('owner')->after('email');
        });
    }
};
