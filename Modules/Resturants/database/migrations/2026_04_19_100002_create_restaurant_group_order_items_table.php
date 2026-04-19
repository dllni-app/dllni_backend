<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_group_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_order_id')->constrained('restaurant_group_orders')->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained('restaurant_group_order_participants')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substitute_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('total_price', 10, 2);
            $table->text('special_instructions')->nullable();
            $table->timestamps();

            $table->index(['group_order_id', 'participant_id'], 'rgoi_group_participant_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_group_order_items');
    }
};
