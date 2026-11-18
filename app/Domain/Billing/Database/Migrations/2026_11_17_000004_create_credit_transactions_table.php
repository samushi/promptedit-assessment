<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only credit ledger. A user's balance is the sum of their rows;
        // nothing is ever updated in place, so there is no balance to drift.
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('generation_id')->nullable()->constrained()->nullOnDelete();
            // purchase | hold | release | charge
            $table->string('type', 20);
            // Signed USD: purchases and releases add, holds and charges subtract.
            $table->decimal('amount_usd', 24, 12);
            // Idempotency key of a purchase (one per "Buy credits" click).
            $table->uuid('reference')->nullable()->unique();
            $table->timestamp('created_at');

            // At most one hold, one release and one charge per generation: the
            // database itself makes a double debit impossible.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
