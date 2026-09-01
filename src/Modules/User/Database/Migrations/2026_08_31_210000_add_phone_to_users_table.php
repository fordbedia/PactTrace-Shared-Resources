<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A free-text contact phone number for a user, shown/edited on the
 * `/profile` screen (Dashboard/Your Profile.html — the "Phone" field on the
 * identity card).
 *
 * Nullable — no user has one until they fill it in, and nothing functional
 * depends on it (the `clients` table already carries its own `phone` for the
 * client-facing side; this is the provider-side login's own number).
 *
 * Regenerate the test DB snapshot after pulling this — see the top-level
 * CLAUDE.md, "Unit testing".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
