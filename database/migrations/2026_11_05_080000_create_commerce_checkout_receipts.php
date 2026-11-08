<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_checkout_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('section', 24);
            $table->unsignedBigInteger('cart_id');
            $table->string('idempotency_key', 80);
            $table->char('payload_fingerprint', 64);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('status', 16)->default('processing');
            $table->timestamps();

            $table->unique(['user_id', 'section', 'idempotency_key'], 'commerce_receipts_user_section_key_unique');
            $table->index(['section', 'order_id'], 'commerce_receipts_order_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_checkout_receipts');
    }
};
