<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->index(['restaurant_id', 'parent_id', 'sort_order', 'id'], 'restaurant_menu_tree_idx');
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->json('included_components')->nullable();
        });
        Schema::table('food_discovery_categories', function (Blueprint $table): void {
            $table->json('aliases')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('food_discovery_categories', fn (Blueprint $table) => $table->dropColumn('aliases'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('included_components'));
        Schema::table('categories', function (Blueprint $table): void {
            $table->dropIndex('restaurant_menu_tree_idx');
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('is_active');
        });
    }
};
