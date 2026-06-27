<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cleaning_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('reward'); // reward | suspend (legacy)
            $table->string('trigger_type')->default('total_hours');
            $table->string('reward_type')->default('free_hours');
            $table->decimal('reward_value', 10, 2)->default(0);
            $table->decimal('min_hours', 8, 2)->nullable();
            $table->unsignedSmallInteger('period_months')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cleaning_automation_rules');
    }
};
