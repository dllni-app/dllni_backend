<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_smart_list_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('smart_list_id')->constrained('restaurant_smart_lists')->cascadeOnDelete();
            $table->foreignId('master_product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('unit')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['smart_list_id', 'master_product_id'], 'rsli_list_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_smart_list_items');
    }
};
