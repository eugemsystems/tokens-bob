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
        Schema::create('pesepay_status_checks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id')->index();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference_number')->nullable()->index();
            $table->string('status_before')->nullable();
            $table->string('status_returned')->nullable();
            $table->string('status_after')->nullable();
            $table->boolean('was_updated')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pesepay_status_checks');
    }
};
