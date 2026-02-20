<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_offer_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('offer_id')->constrained('sm_offers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('sm_products')->cascadeOnDelete();
            $table->decimal('offer_price', 12, 2)->nullable();
            $table->unsignedInteger('max_quantity')->nullable();
            $table->timestamps();

            $table->unique(['offer_id', 'product_id'], 'sm_offer_prod_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_offer_products');
    }
};
