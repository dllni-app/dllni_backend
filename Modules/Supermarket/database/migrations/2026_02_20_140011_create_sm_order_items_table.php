<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('sm_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('sm_products')->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_price', 12, 2);
            $table->string('product_name')->nullable();
            $table->timestamps();

            $table->index('order_id', 'sm_ord_item_order_idx');
            $table->index('product_id', 'sm_ord_item_prod_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_order_items');
    }
};
