<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_discovery_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('name_ar', 120);
            $table->string('name_en', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order']);
        });

        Schema::table('products', function (Blueprint $table): void {
            // Additive classification: preserve all existing catalog and historical order IDs.
            $table->string('product_kind', 32)->nullable();
            $table->string('catalog_role', 24)->default('independent');
            $table->string('classification_status', 24)->default('unreviewed');
            $table->foreignId('primary_food_category_id')
                ->nullable()
                ->constrained('food_discovery_categories')
                ->nullOnDelete();
            $table->timestamp('classified_at')->nullable();
            $table->index(
                ['catalog_role', 'classification_status', 'product_kind'],
                'products_food_discovery_eligibility_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex('products_food_discovery_eligibility_idx');
            $table->dropConstrainedForeignId('primary_food_category_id');
            $table->dropColumn([
                'product_kind',
                'catalog_role',
                'classification_status',
                'classified_at',
            ]);
        });

        Schema::dropIfExists('food_discovery_categories');
    }
};
