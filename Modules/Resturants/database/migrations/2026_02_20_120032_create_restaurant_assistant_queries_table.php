<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_assistant_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('input_mode');
            $table->text('query_text');
            $table->foreignId('matched_recipe_id')->nullable()->constrained('recipes')->nullOnDelete();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'restaurant_id', 'created_at'], 'raq_user_rest_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_assistant_queries');
    }
};
