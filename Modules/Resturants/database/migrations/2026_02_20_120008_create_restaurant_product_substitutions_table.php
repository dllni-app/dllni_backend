<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_product_substitutions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('substitute_product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['restaurant_id', 'product_id', 'substitute_product_id'], 'rps_rest_prod_sub_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_product_substitutions');
    }
};
