<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_item_product', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity_used', 12, 4)->default(1);
            $table->timestamps();

            $table->unique(['inventory_item_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_item_product');
    }
};
