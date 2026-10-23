<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_order_inventory_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id');
            $table->foreign('order_id', 'rest_order_inventory_order_fk')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreignId('inventory_item_id');
            $table->foreign('inventory_item_id', 'rest_order_inventory_item_fk')->references('id')->on('inventory_items')->cascadeOnDelete();
            $table->decimal('quantity_consumed', 12, 4);
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'inventory_item_id'], 'restaurant_order_inventory_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_order_inventory_consumptions');
    }
};
