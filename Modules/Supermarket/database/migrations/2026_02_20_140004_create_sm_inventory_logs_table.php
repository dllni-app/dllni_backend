<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_inventory_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('sm_products')->cascadeOnDelete();
            $table->string('type');
            $table->integer('quantity_change');
            $table->unsignedInteger('quantity_after')->nullable();
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'type', 'created_at'], 'sm_inv_prod_type_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_inventory_logs');
    }
};
