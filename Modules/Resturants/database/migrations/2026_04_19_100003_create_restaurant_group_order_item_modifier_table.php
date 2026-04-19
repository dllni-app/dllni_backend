<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_group_order_item_modifier', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_order_item_id')->constrained('restaurant_group_order_items')->cascadeOnDelete();
            $table->foreignId('modifier_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['group_order_item_id', 'modifier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_group_order_item_modifier');
    }
};
