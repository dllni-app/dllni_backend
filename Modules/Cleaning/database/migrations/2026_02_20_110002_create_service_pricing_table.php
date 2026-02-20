<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_pricing', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_service_id')->constrained()->cascadeOnDelete();
            $table->string('property_type');
            $table->string('living_room_size')->nullable();
            $table->decimal('base_price', 10, 2);
            $table->decimal('price_per_sqm', 10, 2)->nullable();
            $table->decimal('min_hours', 8, 2)->nullable();
            $table->timestamps();

            $table->index('cleaning_service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_pricing');
    }
};
