<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sm_recurring_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('sm_stores')->cascadeOnDelete();
            $table->string('status');
            $table->string('frequency');
            $table->json('frequency_config')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'sm_recur_user_status_idx');
            $table->index('next_run_at', 'sm_recur_next_run_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sm_recurring_orders');
    }
};
