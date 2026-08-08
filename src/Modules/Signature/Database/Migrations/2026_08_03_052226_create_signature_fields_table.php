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
        Schema::create('signature_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('envelopes')->cascadeOnDelete();
            $table->foreignId('signer_id')->constrained('signers')->cascadeOnDelete();
            $table->enum('field_type', ['signature', 'initial', 'date', 'text'])->default('signature');
            $table->unsignedInteger('page_number')->default(1);
            $table->float('x_position');
            $table->float('y_position');
            $table->float('width');
            $table->float('height');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('signature_fields');
    }
};
