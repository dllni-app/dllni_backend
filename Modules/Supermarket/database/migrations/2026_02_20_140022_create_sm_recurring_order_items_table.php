<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_recurring_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recurring_order_id')->constrained('sm_recurring_orders')->cascadeOnDelete();
            $table->foreignId('master_product_id')->constrained('master_products')->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('recurring_order_id', 'sm_recur_item_order_idx');
            $table->index('master_product_id', 'sm_recur_item_master_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_recurring_order_items');
    }
};
