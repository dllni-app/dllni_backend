<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained('sm_carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('sm_products')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2);
            $table->timestamps();

            $table->index('cart_id', 'sm_cart_item_cart_idx');
            $table->index('product_id', 'sm_cart_item_prod_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_cart_items');
    }
};
