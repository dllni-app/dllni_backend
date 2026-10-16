<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_supervisor_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('released_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->index(['cleaning_booking_id', 'released_at'], 'csa_booking_active_idx');
            $table->index(['supervisor_user_id', 'released_at'], 'csa_supervisor_active_idx');
        });

        Schema::create('cleaning_supervisor_activity', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['cleaning_booking_id', 'created_at'], 'csa_activity_booking_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_supervisor_activity');
        Schema::dropIfExists('cleaning_supervisor_assignments');
    }
};
