<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_modifier_group_product', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained('sm_modifier_groups')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('sm_products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['modifier_group_id', 'product_id'], 'sm_mod_group_product_unique');
            $table->index(['product_id', 'modifier_group_id'], 'sm_mod_group_product_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_modifier_group_product');
    }
};
