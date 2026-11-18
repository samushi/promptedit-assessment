<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Our id is sent to Pixelforge as metadata.generation_id so that a webhook
            // arriving before the POST response can still find this row.
            $table->uuid('id')->primary();
            // Kept (as null) if the user deletes their account: these are billing records.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Idempotency for our own form: one logical request per form submission.
            $table->uuid('client_request_id');
            // Sent as Idempotency-Key on every POST attempt for this generation; never changes.
            $table->uuid('idempotency_key')->unique();
            $table->string('provider_generation_id')->nullable()->unique();

            $table->string('status', 20);
            $table->text('prompt');
            $table->string('size', 9);
            $table->string('quality', 6);
            $table->json('reference_images');
            // The exact POST /v1/generations body, built once and replayed verbatim on retries.
            $table->json('provider_request');

            // Money: exact decimals, never floats. 12 places hold Pixelforge's 9 plus the markup.
            $table->decimal('estimated_provider_cost_usd', 24, 12);
            $table->decimal('estimated_customer_cost_usd', 24, 12);
            $table->decimal('max_customer_cost_usd', 24, 12);
            $table->decimal('actual_provider_cost_usd', 24, 12)->nullable();
            $table->decimal('actual_customer_cost_usd', 24, 12)->nullable();

            // The generation's own immutable rate snapshot (per 1M tokens) and its final usage.
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedSmallInteger('submission_attempts')->default(0);
            // Failed POST attempts that count towards the retry budget (waiting for
            // a concurrency slot doesn't: that has its own time budget).
            $table->unsignedSmallInteger('submission_failures')->default(0);
            // True once any POST attempt had an unknown outcome (timeout, bare 5xx...):
            // Pixelforge may have created it, so we must never assume it was rejected.
            $table->boolean('submission_ambiguous')->default(false);

            // Pixelforge's own timestamps (UTC). completed_at is the billing day.
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_request_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
