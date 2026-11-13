<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['status', 'created_at'], 'restaurant_orders_status_created_idx');
        });
        Schema::table('order_items', function (Blueprint $table): void {
            $table->index(['order_id', 'product_id', 'quantity'], 'restaurant_items_sales_cover_idx');
        });
    }

    public function down(): void
    {
        // MySQL may remove the original implicit FK index when the covering
        // index is created. Restore a dedicated index before rolling it back.
        if (! Schema::hasIndex('order_items', ['order_id'])) {
            Schema::table('order_items', fn (Blueprint $table) => $table->index('order_id', 'order_items_order_id_foreign'));
        }
        Schema::table('order_items', fn (Blueprint $table) => $table->dropIndex('restaurant_items_sales_cover_idx'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex('restaurant_orders_status_created_idx'));
    }
};