<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->boolean('is_token')->default(true)->after('description');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->boolean('is_webhook_purchase')->default(false)->after('gateway_payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropColumn('is_token');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropColumn('is_webhook_purchase');
        });
    }
};
