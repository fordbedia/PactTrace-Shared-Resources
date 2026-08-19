<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `provider_signer_id` is Documenso's own recipient id for this signer —
 * distinct from this row's own primary key, same relationship as
 * envelopes.provider_envelope_id to envelopes.id. Nullable: a Signer row is
 * created locally the first time a client opens their embedded signing
 * screen (GenerateSigningEmbedTokenUseCase resolves it from Documenso and
 * backfills this column), not when the tenant adds recipients inside
 * Documenso's own editor — see .claude/rules/signature.md, "Field placement".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signers', function (Blueprint $table) {
            $table->string('provider_signer_id')->nullable()->after('envelope_id');
        });
    }

    public function down(): void
    {
        Schema::table('signers', function (Blueprint $table) {
            $table->dropColumn('provider_signer_id');
        });
    }
};
