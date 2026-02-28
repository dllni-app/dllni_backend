<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_financial_settings', function (Blueprint $table): void {
            $table->id();
            $table->decimal('base_hour_price', 10, 2)->default(0);
            $table->unsignedInteger('min_hours')->default(1);
            $table->json('addons_pricing')->nullable();
            $table->string('commission_type')->default('percent');
            $table->decimal('commission_value', 10, 2)->default(0);
            $table->decimal('travel_per_km', 10, 2)->default(0);
            $table->decimal('travel_minimum', 10, 2)->default(0);
            $table->string('distance_start_point')->default('auto');
            $table->string('billing_mode')->default('actual');
            $table->unsignedInteger('min_actual_minutes')->nullable();
            $table->unsignedInteger('time_warning_minutes_before_end')->default(15);
            $table->json('coverage_thresholds')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_financial_settings');
    }
};
