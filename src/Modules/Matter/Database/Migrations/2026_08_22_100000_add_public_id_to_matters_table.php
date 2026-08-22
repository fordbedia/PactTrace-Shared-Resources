<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Adds a non-sequential public identifier to `matters`, mirroring
 * `2026_08_21_100000_add_public_id_to_envelopes_table` exactly — see
 * .claude/rules/signature.md, "Envelope public identifier", and
 * .claude/rules/matter.md. The client portal's matter detail route
 * (`/portal/matter/{matterId}`) resolves by this instead of the enumerable
 * auto-increment `id`. Internal PK/FK relationships are untouched;
 * `public_id` is only ever used for route-model binding
 * (Matter::getRouteKeyName()) and outbound URLs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->unique()->after('id');
        });

        DB::table('matters')->whereNull('public_id')->orderBy('id')->each(function (object $matter) {
            DB::table('matters')
                ->where('id', $matter->id)
                ->update(['public_id' => (string) Str::ulid()]);
        });
    }

    public function down(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
