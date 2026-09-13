<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `/account-settings`'s "Firm Details" card previously had no persistence at
 * all beyond `business_name` (already on `providers` — see the
 * 2026_08_03 create migration) — Firm Email / Firm Phone / Business Address
 * were local component state with a fake "saved" toast. These four columns
 * are what let the whole card round-trip for real. See
 * .claude/rules/account-settings.md.
 *
 *  - `firm_email` / `firm_phone`  the firm's own contact details, distinct
 *                                 from the acting user's own `users.email`/
 *                                 `users.phone` (see .claude/rules/profile.md).
 *  - `address_line1` / `address_line2`  the two Business Address text areas
 *                                       on the card, kept as separate columns
 *                                       to match the two on-screen fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('firm_email')->nullable()->after('business_name');
            $table->string('firm_phone')->nullable()->after('firm_email');
            $table->string('address_line1')->nullable()->after('firm_phone');
            $table->string('address_line2')->nullable()->after('address_line1');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn(['firm_email', 'firm_phone', 'address_line1', 'address_line2']);
        });
    }
};
