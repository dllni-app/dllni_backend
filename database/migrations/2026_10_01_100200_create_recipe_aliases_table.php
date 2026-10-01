<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
            $table->string('alias');
            $table->timestamps();

            $table->unique(['recipe_id', 'alias']);
            $table->index('alias');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_aliases');
    }
};
