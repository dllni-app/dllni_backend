<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_v3_supervisor_attendance_proofs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_id')->unique('cv3_sa_visit_uq')
                ->constrained('cleaning_booking_sessions', 'id', 'cv3_sa_visit_fk')->restrictOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users', 'id', 'cv3_sa_supervisor_fk')->restrictOnDelete();
            $table->foreignId('supervisor_assignment_id')->constrained('cleaning_supervisor_assignments', 'id', 'cv3_sa_assignment_fk')->restrictOnDelete();
            $table->unsignedInteger('clock_revision');
            $table->timestamp('present_from');
            $table->timestamp('present_until');
            $table->unsignedInteger('active_seconds');
            $table->string('reference', 128)->unique('cv3_sa_reference_uq');
            $table->string('evidence', 500);
            $table->foreignId('recorded_by')->constrained('users', 'id', 'cv3_sa_recorder_fk')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users', 'id', 'cv3_sa_verifier_fk')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('cleaning_v3_supervisor_attendance_proofs')->exists()) {
            throw new RuntimeException('Cannot delete V3 supervisor attendance evidence.');
        }
        Schema::dropIfExists('cleaning_v3_supervisor_attendance_proofs');
    }
};
