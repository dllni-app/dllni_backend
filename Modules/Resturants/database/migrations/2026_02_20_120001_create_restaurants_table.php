<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('total_reviews')->default(0);
            $table->unsignedInteger('estimated_preparation_time')->default(15);
            $table->decimal('minimum_order_amount', 10, 2)->default(0);
            $table->string('price_range');
            $table->integer('reputation_score')->default(100);
            $table->unsignedInteger('warning_count')->default(0);
            $table->integer('visibility_score')->default(100);
            $table->boolean('manual_visibility_override')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->timestamp('suspension_until')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'is_featured']);
            $table->index(['average_rating', 'reputation_score', 'visibility_score'], 'rest_rating_rep_vis_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurants');
    }
};
