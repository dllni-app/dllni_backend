<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained('sm_stores')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('offer_type');
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->unsignedTinyInteger('discount_percent')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['store_id', 'is_active'], 'sm_off_store_active_idx');
            $table->index(['starts_at', 'ends_at'], 'sm_off_dates_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_offers');
    }
};
