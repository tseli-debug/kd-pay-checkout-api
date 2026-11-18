<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payment_link_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 32)->unique();
            $table->string('provider', 24);
            $table->string('provider_reference', 100)->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('fee_amount', 12, 2)->nullable();
            $table->decimal('net_amount', 12, 2)->nullable();
            $table->char('currency', 3)->default('LSL');
            $table->string('status', 20)->default('initiated');
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'idempotency_key']);
            $table->index(['merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
