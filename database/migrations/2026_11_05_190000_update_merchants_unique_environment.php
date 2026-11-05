<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->dropUnique('merchants_merchant_code_unique');
            if (Schema::hasIndex('merchants', 'midtrans_merchant_code_env_idx')) {
                $table->dropIndex('midtrans_merchant_code_env_idx');
            }
            $table->unique(['merchant_code', 'environment'], 'merchants_merchant_code_env_unique');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->dropUnique('merchants_merchant_code_env_unique');
            $table->unique('merchant_code', 'merchants_merchant_code_unique');
            $table->index(['merchant_code', 'environment'], 'midtrans_merchant_code_env_idx');
        });
    }
};
