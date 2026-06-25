<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'gateway_payment_id')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('gateway_payment_id')->nullable()->after('gateway');
            });
        }
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('gateway_payment_id');
        });
    }
};
