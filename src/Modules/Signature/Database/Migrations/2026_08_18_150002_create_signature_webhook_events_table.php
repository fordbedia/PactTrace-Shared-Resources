<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Raw inbound webhook log, kept simple per the feature spec ("no need for a
 * generic outbox pattern") — one table, unique `payload_hash` is the whole
 * idempotency mechanism: DocumensoWebhookController does a firstOrCreate on
 * it, so replaying the exact same payload twice is a no-op the second time
 * (see RecordSignatureCompletionUseCase). `envelope_id` is nullable and
 * `nullOnDelete` because a payload might reference an envelope this install
 * doesn't recognise (wrong provider_envelope_id, or an envelope tied to a
 * different environment) — the row is still worth keeping for debugging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('documenso');
            $table->string('event_type');
            $table->string('provider_envelope_id')->nullable();
            $table->foreignId('envelope_id')->nullable()->constrained('envelopes')->nullOnDelete();
            $table->json('payload');
            $table->string('payload_hash', 64)->unique();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_webhook_events');
    }
};
