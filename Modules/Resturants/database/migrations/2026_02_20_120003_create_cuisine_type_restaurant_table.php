<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuisine_type_restaurant', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cuisine_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['cuisine_type_id', 'restaurant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuisine_type_restaurant');
    }
};
