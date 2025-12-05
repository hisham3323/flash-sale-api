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
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            // The unique key from the payment provider (Stripe, etc.)
            $table->string('idempotency_key')->unique();
            
            // Link to the order
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            
            // What was the status reported in this webhook?
            $table->string('status'); // 'success', 'failed'
            
            // Store the full payload for debugging (Requirement: structured logging)
            $table->json('payload')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};