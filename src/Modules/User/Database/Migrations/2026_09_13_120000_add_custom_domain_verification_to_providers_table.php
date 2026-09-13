<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `providers.custom_domain` (see 2026_08_03_052200_create_providers_table)
 * has existed since the very first migration as a plain saved-but-inert
 * string — nothing verified it was actually pointed at PactTrack, and
 * nothing served the portal on it. This adds the columns that let
 * `/dashboard/branding`'s Custom Domain card carry a real DNS-verification
 * + TLS-provisioning lifecycle instead of a fire-and-forget text field. See
 * .claude/rules/branding.md.
 *
 *  - `custom_domain_status`               unverified -> pending -> verified,
 *                                         or failed (a genuine DNS lookup
 *                                         error, not just "not set up yet").
 *  - `custom_domain_verification_token`   the TXT-record value a tenant is
 *                                         asked to publish at
 *                                         `_pacttrack-verify.{domain}`.
 *  - `custom_domain_verified_at`          when DNS verification last
 *                                         succeeded; cleared whenever the
 *                                         domain string changes.
 *  - `cloudflare_custom_hostname_id`      Cloudflare's id for the Custom
 *                                         Hostname for SaaS registration
 *                                         (Part 2 — TLS provisioning),
 *                                         triggered automatically the
 *                                         moment DNS verification succeeds.
 *  - `custom_domain_ssl_status`           Cloudflare's own SSL status for
 *                                         that hostname (e.g.
 *                                         `pending_validation` / `active` /
 *                                         `failed`) — separate from
 *                                         `custom_domain_status`, which
 *                                         tracks DNS only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('custom_domain_status')->default('unverified')->after('custom_domain');
            $table->string('custom_domain_verification_token')->nullable()->after('custom_domain_status');
            $table->timestamp('custom_domain_verified_at')->nullable()->after('custom_domain_verification_token');
            $table->string('cloudflare_custom_hostname_id')->nullable()->after('custom_domain_verified_at');
            $table->string('custom_domain_ssl_status')->nullable()->after('cloudflare_custom_hostname_id');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn([
                'custom_domain_status',
                'custom_domain_verification_token',
                'custom_domain_verified_at',
                'cloudflare_custom_hostname_id',
                'custom_domain_ssl_status',
            ]);
        });
    }
};
