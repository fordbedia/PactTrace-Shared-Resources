<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            // Backs Application/UseCases/ProcessTrialExpirations' daily scan:
            // WHERE status = 'trialing' AND trial_ends_at <= ?. Composite so the
            // equality half (status) narrows before the range scan on
            // trial_ends_at, instead of a full table scan filtered in memory.
            $table->index(['status', 'trial_ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['status', 'trial_ends_at']);
        });
    }
};
