<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('public_id')->unique();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('LSL');
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->string('success_url', 2048)->nullable();
            $table->string('cancel_url', 2048)->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_links');
    }
};
