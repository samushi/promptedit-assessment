<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Inbox of verified Pixelforge webhooks. The unique event_id is what makes
        // at-least-once delivery safe: a redelivered event can never be stored twice.
        Schema::create('pixelforge_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->string('provider_generation_id')->nullable()->index();
            // The verified raw body, stored as received.
            $table->json('payload');
            $table->unsignedSmallInteger('delivery_attempt')->nullable();
            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();

            $table->index(['processed_at', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pixelforge_webhook_events');
    }
};
