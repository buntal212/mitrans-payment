<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('merchant_order_id', 100);
            $table->string('midtrans_transaction_id', 100)->nullable()->index();
            $table->decimal('gross_amount', 15, 2);
            $table->string('currency', 10)->default('IDR');
            $table->string('payment_type', 50)->default('qris');
            $table->string('transaction_status', 50)->default('pending')->index();
            $table->string('fraud_status', 30)->nullable();
            $table->string('status_code', 20)->nullable();
            $table->text('status_message')->nullable();
            $table->text('checkout_url')->nullable();
            $table->text('qr_string')->nullable();
            $table->json('va_numbers')->nullable();
            $table->json('raw_response')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamps();

            $table->unique(['merchant_id', 'merchant_order_id'], 'midtrans_merchant_order_unique');
        });

        Schema::create('payment_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->cascadeOnDelete();
            $table->foreignUlid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('order_id', 100)->index();
            $table->string('transaction_id', 100)->nullable();
            $table->string('signature_key', 255)->nullable();
            $table->boolean('is_signature_valid')->default(false);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhooks');
        Schema::dropIfExists('payments');
    }
};
