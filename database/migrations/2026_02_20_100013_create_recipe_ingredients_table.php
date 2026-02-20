<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('master_product_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 4);
            $table->string('unit');
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            $table->index(['recipe_id', 'master_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
    }
};
