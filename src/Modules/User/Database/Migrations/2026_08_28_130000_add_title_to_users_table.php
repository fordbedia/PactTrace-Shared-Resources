<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A free-text job title / position for a staff user ("Attorney",
 * "Paralegal", "Office Manager") — a DISPLAY label only, shown in the
 * client portal's staff contact directory (see .claude/rules/messaging.md,
 * "Portal: staff contact directory").
 *
 * Deliberately not modelled on the role/permission system: `Role` /
 * `Permission` answer "what may this user do", never "what is their job
 * title". Nullable — most existing users have none, and it is never
 * required for anything functional.
 *
 * Regenerate the test DB snapshot after pulling this — see the top-level
 * CLAUDE.md, "Unit testing".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('title')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
