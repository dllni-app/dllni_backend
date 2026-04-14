<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_smart_list_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('smart_list_id')->constrained('sm_smart_lists')->cascadeOnDelete();
            $table->string('frequency_type');
            $table->unsignedTinyInteger('day_of_week')->nullable();
            $table->unsignedTinyInteger('day_of_month')->nullable();
            $table->date('run_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->unique('smart_list_id', 'sm_slist_schedule_list_unique');
            $table->index(['is_active', 'next_run_at'], 'sm_slist_schedule_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_smart_list_schedules');
    }
};
