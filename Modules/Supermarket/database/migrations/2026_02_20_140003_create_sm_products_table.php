<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('sm_stores')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('sm_categories')->cascadeOnDelete();
            $table->foreignId('master_product_id')->nullable()->constrained('master_products')->nullOnDelete();
            $table->string('name');
            $table->string('barcode')->nullable();
            $table->string('source_type');
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('discounted_price', 12, 2)->nullable();
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'is_available'], 'sm_prod_store_avail_idx');
            $table->index('category_id', 'sm_prod_category_idx');
            $table->index('master_product_id', 'sm_prod_master_idx');
            $table->index('barcode', 'sm_prod_barcode_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_products');
    }
};
