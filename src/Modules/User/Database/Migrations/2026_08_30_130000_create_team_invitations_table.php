<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending staff/owner invitations for a provider.
 *
 * An invited person is NOT a `users` row yet — they have no password and have
 * accepted nothing. That is exactly why this is its own table rather than a
 * half-real `users` row every other query over `users` would then have to
 * filter out (see InviteTeamMember's own history: it used to insert straight
 * into `users` and failed on the NOT NULL `password` column).
 *
 * `token` is the secret, single-use link identifier. It is only ever looked up
 * on the PUBLIC accept-invitation endpoint — never used as a route key on any
 * staff-authenticated route, where it would leak into browser history/logs.
 *
 * Regenerate the test DB snapshot after pulling this — see the top-level
 * CLAUDE.md, "Unit testing".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('title')->nullable();
            // Provider-side roles only — an invitation can never grant 'client'.
            $table->enum('role', ['owner', 'staff']);
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            // Backs the "is there already a pending invite for this email?"
            // check in InviteTeamMember. Deliberately NOT unique: whether a
            // second invite to a pending email resends or rejects is an
            // application rule (with its own error message), enforced in
            // InviteTeamMember, not a DB constraint.
            $table->index(['provider_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_invitations');
    }
};
