<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_group_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cuisine_type_id')->nullable()->constrained('cuisine_types')->nullOnDelete();
            $table->string('food_category_hint')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->timestamp('ends_at');
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['status', 'ends_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_group_votes');
    }
};
