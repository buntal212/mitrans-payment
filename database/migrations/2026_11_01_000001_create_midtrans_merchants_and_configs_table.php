<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table): void {
            $table->id();
            $table->string('merchant_code', 60)->unique();
            $table->string('merchant_name', 100);
            $table->string('environment', 20)->default('sandbox');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['merchant_code', 'environment'], 'midtrans_merchant_code_env_idx');
        });

        Schema::create('merchant_configurations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->unique()->constrained('merchants')->cascadeOnDelete();
            $table->string('midtrans_merchant_id', 100)->nullable();
            $table->string('client_key', 150)->nullable();
            $table->text('encrypted_server_key')->nullable();
            $table->text('encrypted_signature_key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_configurations');
        Schema::dropIfExists('merchants');
    }
};
