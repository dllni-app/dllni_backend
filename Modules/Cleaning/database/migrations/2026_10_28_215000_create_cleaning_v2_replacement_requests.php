<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_booking_session_replacement_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_id');
            $table->foreignId('cleaning_booking_session_worker_assignment_id');
            $table->foreignId('cleaning_booking_session_worker_attestation_id');
            $table->foreignId('cleaning_booking_session_supervisor_assignment_id');
            $table->foreignId('requested_by_user_id');
            $table->string('status', 24)->default('pending');
            $table->text('reason');
            $table->timestamps();

            $table->foreign('cleaning_booking_session_id', 'cbrr_visit_fk')
                ->references('id')->on('cleaning_booking_sessions')->restrictOnDelete();
            $table->foreign('cleaning_booking_session_worker_assignment_id', 'cbrr_worker_fk')
                ->references('id')->on('cleaning_booking_session_worker_assignments')->restrictOnDelete();
            $table->foreign('cleaning_booking_session_worker_attestation_id', 'cbrr_attestation_fk')
                ->references('id')->on('cleaning_booking_session_worker_attestations')->restrictOnDelete();
            $table->foreign('cleaning_booking_session_supervisor_assignment_id', 'cbrr_supervisor_fk')
                ->references('id')->on('cleaning_booking_session_supervisor_assignments')->restrictOnDelete();
            $table->foreign('requested_by_user_id', 'cbrr_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique('cleaning_booking_session_worker_attestation_id', 'cbrr_attestation_unique');
            $table->index(['cleaning_booking_session_id', 'status'], 'cbrr_visit_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_session_replacement_requests');
    }
};
