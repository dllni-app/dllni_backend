<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_checkout_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('section', 24);
            $table->unsignedBigInteger('cart_id');
            $table->char('cart_fingerprint', 64);
            $table->json('selection');
            $table->json('amounts');
            $table->dateTime('expires_at');
            $table->unsignedBigInteger('consumed_order_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'section', 'cart_id'], 'commerce_quotes_owner_cart_idx');
            $table->index('expires_at', 'commerce_quotes_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_checkout_quotes');
    }
};
