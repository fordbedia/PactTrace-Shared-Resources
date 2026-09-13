<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `providers.email_powered_by_footer` (added by the 2026-09-04 branding
 * migration, `default(true)`) was a dead column until this change — nothing
 * anywhere read it. Now that ClientInvitationEmail / DocumentReadyForSignatureEmail
 * / GuestSigningInvitationEmail actually respect it
 * (ProviderData::showsPoweredByFooter(), see .claude/rules/branding.md,
 * "Email Branding"), its `true` default would have silently ADDED a "Powered
 * by PactTrack" line to every Professional/Firm tenant's client-facing
 * emails the moment this shipped — directly reversing the already-shipped,
 * already-tested "no PactTrack mark anywhere" white-labeling decision (see
 * GuestSigningInvitationEmailTest::test_a_professional_or_firm_tenant_is_fully_white_labeled,
 * .claude/rules/notification.md).
 *
 * Flips the column's default to `false` and backfills every existing row to
 * match — nobody has ever made a deliberate choice with this column before
 * today, so there is no real preference being overwritten, only unused
 * default noise. A Professional/Firm tenant who wants the co-branding line
 * now has to explicitly turn it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE providers ALTER email_powered_by_footer SET DEFAULT 0');
        DB::table('providers')->update(['email_powered_by_footer' => false]);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE providers ALTER email_powered_by_footer SET DEFAULT 1');
    }
};
