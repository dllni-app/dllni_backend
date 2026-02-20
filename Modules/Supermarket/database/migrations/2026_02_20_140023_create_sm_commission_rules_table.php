<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_commission_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('sm_stores')->cascadeOnDelete();
            $table->string('commission_type');
            $table->decimal('value', 10, 2);
            $table->decimal('min_order_amount', 12, 2)->nullable();
            $table->decimal('max_commission_amount', 12, 2)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['store_id', 'is_active', 'is_default'], 'sm_comm_store_active_default_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_commission_rules');
    }
};
