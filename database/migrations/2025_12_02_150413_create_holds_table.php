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
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            // Link to the products table. If product is deleted, delete the hold.
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('qty');
            $table->timestamp('expires_at');
            $table->timestamps();
            
            // Index for faster queries when checking expired holds
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};