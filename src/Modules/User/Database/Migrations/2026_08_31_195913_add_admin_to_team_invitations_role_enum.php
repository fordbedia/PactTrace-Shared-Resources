<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Admin" is now a first-class Role (owner > admin > staff > client), not
 * "staff + layered permissions". The invite modal offers Admin / Staff, so the
 * invitation column moves from enum('owner','staff') to enum('admin','staff').
 *
 * Done in three steps — widen to the union first — because MySQL (in strict
 * mode) rejects `UPDATE ... SET role = 'admin'` while 'admin' is not yet an
 * allowed enum value ("Data truncated for column 'role'"). Earlier "Admin"
 * invites were stored as `owner` (the old overload); those rows are rewritten
 * to `admin` in the middle step. Raw DB::statement because Laravel can't ALTER
 * an ENUM's allowed values without doctrine/dbal, which this package doesn't
 * pull in.
 *
 * Regenerate the test DB snapshot after pulling this — see the top-level
 * CLAUDE.md, "Unit testing".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE team_invitations MODIFY COLUMN role ENUM('owner', 'admin', 'staff') NOT NULL");

        DB::table('team_invitations')->where('role', 'owner')->update(['role' => 'admin']);

        DB::statement("ALTER TABLE team_invitations MODIFY COLUMN role ENUM('admin', 'staff') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE team_invitations MODIFY COLUMN role ENUM('owner', 'admin', 'staff') NOT NULL");

        DB::table('team_invitations')->where('role', 'admin')->update(['role' => 'owner']);

        DB::statement("ALTER TABLE team_invitations MODIFY COLUMN role ENUM('owner', 'staff') NOT NULL");
    }
};
