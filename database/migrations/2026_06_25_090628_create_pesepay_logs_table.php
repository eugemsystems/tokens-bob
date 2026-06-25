<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pesepay_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('transaction_id')->nullable()->index();
            $table->string('event');
            $table->string('reference_number')->nullable()->index();
            $table->string('payment_method')->nullable();
            $table->integer('http_status')->nullable();
            $table->string('transaction_status')->nullable();
            $table->integer('status_code')->nullable();
            $table->text('status_description')->nullable();
            $table->json('raw_payload')->nullable();
            $table->boolean('success');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pesepay_logs');
    }
};
