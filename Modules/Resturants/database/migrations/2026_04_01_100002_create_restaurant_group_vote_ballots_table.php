<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_group_vote_ballots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vote_id')->constrained('restaurant_group_votes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('restaurant_group_vote_options')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vote_id', 'user_id']);
            $table->index('option_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_group_vote_ballots');
    }
};
