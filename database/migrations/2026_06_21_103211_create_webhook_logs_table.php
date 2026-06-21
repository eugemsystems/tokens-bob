<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('event_type')->nullable();
            $table->longText('payload')->nullable();
            $table->text('headers')->nullable();
            $table->string('status')->default('received'); // received | processed | failed
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['source', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
