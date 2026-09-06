<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns the `/dashboard/branding` rebuild needs on `providers`. Everything
 * here is provider-level branding/config the artboard already shows fields
 * for — no new concept. Client/engagement terminology deliberately stays on
 * `workspaces` (client_label / engagement_label) and is NOT duplicated here.
 *
 *  - `disk`                     which filesystem the logo bytes live on, so a
 *                               future move of some tenants to S3 is a data
 *                               migration, not a code change. Mirrors the
 *                               intent of Document's per-file `disk`.
 *  - `timezone` / `locale`      Portal Details "Timezone" / "Portal Language".
 *  - `email_sender_name`        Email Branding "Sender Name".
 *  - `email_reply_to`           Email Branding "Reply-To Email".
 *  - `email_powered_by_footer`  Email Branding "Powered by PactTrack" toggle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->string('disk')->default('local')->after('logo_path');
            $table->string('timezone')->nullable()->after('secondary_color');
            $table->string('locale')->nullable()->after('timezone');
            $table->string('email_sender_name')->nullable()->after('locale');
            $table->string('email_reply_to')->nullable()->after('email_sender_name');
            $table->boolean('email_powered_by_footer')->default(true)->after('email_reply_to');
        });
    }

    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            $table->dropColumn([
                'disk',
                'timezone',
                'locale',
                'email_sender_name',
                'email_reply_to',
                'email_powered_by_footer',
            ]);
        });
    }
};
