<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_neighborhoods', function (Blueprint $table): void {
            $table->id();
            $table->string('city_name')->default('حلب');
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('normalized_name')->unique();
            $table->json('aliases')->nullable();
            $table->decimal('center_latitude', 10, 7)->nullable();
            $table->decimal('center_longitude', 10, 7)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['city_name', 'is_active']);
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_neighborhoods');
    }
};
