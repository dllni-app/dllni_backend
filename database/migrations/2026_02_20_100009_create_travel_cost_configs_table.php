<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_cost_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('max_km', 10, 2);
            $table->decimal('cost_per_km', 10, 2)->nullable();
            $table->decimal('fixed_fee', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_cost_configs');
    }
};
