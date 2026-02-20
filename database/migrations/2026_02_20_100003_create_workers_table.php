<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->text('bio')->nullable();
            $table->decimal('average_rating', 3, 2)->default(0);
            $table->unsignedInteger('total_completed_jobs')->default(0);
            $table->unsignedInteger('trust_score')->default(0);
            $table->decimal('acceptance_rate', 5, 2)->default(0);
            $table->decimal('cancellation_rate', 5, 2)->default(0);
            $table->unsignedInteger('open_disputes_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_suspended')->default(false);
            $table->timestamp('suspended_until')->nullable();
            $table->string('home_address')->nullable();
            $table->decimal('home_latitude', 10, 8)->nullable();
            $table->decimal('home_longitude', 11, 8)->nullable();
            $table->json('default_working_hours')->nullable();
            $table->timestamps();

            $table->unique('user_id');
            $table->index(['is_active', 'trust_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');
    }
};
