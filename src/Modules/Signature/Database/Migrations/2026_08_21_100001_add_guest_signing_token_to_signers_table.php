<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guest (no-account) signing token for co-signers — see
 * .claude/rules/signature.md, "Guest signers". `signing_token_hash` is the
 * sha256 hex digest of a random bearer token (never the raw token itself —
 * see GuestSigningTokenService); its presence is what distinguishes a guest
 * signer from the primary, portal-authenticated client, so no separate
 * boolean column is added. `signing_token_expires_at` defaults to a 14-day
 * window from issuance; `signing_token_consumed_at` is set once the webhook
 * confirms that signer completed (RecordSignatureCompletionUseCase), so a
 * used link can't be replayed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signers', function (Blueprint $table) {
            $table->string('signing_token_hash', 64)->nullable()->unique()->after('provider_signer_id');
            $table->timestamp('signing_token_expires_at')->nullable()->after('signing_token_hash');
            $table->timestamp('signing_token_consumed_at')->nullable()->after('signing_token_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('signers', function (Blueprint $table) {
            $table->dropColumn(['signing_token_hash', 'signing_token_expires_at', 'signing_token_consumed_at']);
        });
    }
};
