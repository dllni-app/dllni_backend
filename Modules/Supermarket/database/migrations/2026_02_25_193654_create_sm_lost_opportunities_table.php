<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_lost_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('sm_stores')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('sm_products')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('attempted_quantity');
            $table->unsignedInteger('available_stock');
            $table->timestamps();

            $table->index(['store_id', 'product_id', 'created_at'], 'sm_lost_opp_store_prod_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_lost_opportunities');
    }
};
