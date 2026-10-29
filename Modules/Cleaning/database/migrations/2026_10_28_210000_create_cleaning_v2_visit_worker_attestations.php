<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_booking_session_worker_attestations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_id');
            $table->foreignId('cleaning_booking_session_worker_assignment_id');
            $table->foreignId('cleaning_booking_session_supervisor_assignment_id');
            $table->foreignId('recorded_by_user_id');
            $table->string('outcome', 20);
            $table->text('note')->nullable();
            $table->timestamp('certified_at');
            $table->timestamps();

            $table->foreign('cleaning_booking_session_id', 'cbwa_session_fk')
                ->references('id')->on('cleaning_booking_sessions')->restrictOnDelete();
            $table->foreign('cleaning_booking_session_worker_assignment_id', 'cbwa_worker_fk')
                ->references('id')->on('cleaning_booking_session_worker_assignments')->restrictOnDelete();
            $table->foreign('cleaning_booking_session_supervisor_assignment_id', 'cbwa_supervisor_fk')
                ->references('id')->on('cleaning_booking_session_supervisor_assignments')->restrictOnDelete();
            $table->foreign('recorded_by_user_id', 'cbwa_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            // Immutable first decision. Disputed no-shows need a separate audited resolution.
            $table->unique('cleaning_booking_session_worker_assignment_id', 'cbwa_worker_unique');
            $table->index(['cleaning_booking_session_id', 'outcome'], 'cbwa_visit_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_session_worker_attestations');
    }
};
