<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('master_product_categories')) {
            Schema::create('master_product_categories', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index('name');
            });
        }

        if (Schema::hasTable('master_products') && ! Schema::hasColumn('master_products', 'category_id')) {
            Schema::table('master_products', function (Blueprint $table): void {
                $table->foreignId('category_id')
                    ->nullable()
                    ->after('name')
                    ->constrained('master_product_categories')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('master_products') && Schema::hasColumn('master_products', 'category_id')) {
            Schema::table('master_products', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('category_id');
            });
        }

        Schema::dropIfExists('master_product_categories');
    }
};
