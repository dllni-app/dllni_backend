<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_type_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('property_type');
            $table->string('living_room_size')->nullable();
            $table->decimal('base_sqm_min', 10, 2);
            $table->decimal('base_sqm_max', 10, 2);
            $table->decimal('base_hours', 8, 2);
            $table->json('rules')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_type_configs');
    }
};
