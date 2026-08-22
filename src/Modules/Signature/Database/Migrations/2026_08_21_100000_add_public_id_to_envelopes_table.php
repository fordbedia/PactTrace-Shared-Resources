<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds a non-sequential public identifier to `envelopes`, so client-facing
 * URLs (`/portal/sign?envelope=...`, `/docusign-return?...&envelope=...`)
 * never expose the enumerable auto-increment `id` — see
 * .claude/rules/signature.md, "Envelope public identifier". Internal
 * PK/FK relationships are untouched; `public_id` is only ever used for
 * route-model binding (Envelope::getRouteKeyName()) and outbound URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->unique()->after('id');
        });

        DB::table('envelopes')->whereNull('public_id')->orderBy('id')->each(function (object $envelope) {
            DB::table('envelopes')
                ->where('id', $envelope->id)
                ->update(['public_id' => (string) Str::ulid()]);
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
