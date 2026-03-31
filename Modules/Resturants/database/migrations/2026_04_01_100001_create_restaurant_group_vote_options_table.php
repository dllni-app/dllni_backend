<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_group_vote_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vote_id')->constrained('restaurant_group_votes')->cascadeOnDelete();
            $table->string('label');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('vote_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_group_vote_options');
    }
};
