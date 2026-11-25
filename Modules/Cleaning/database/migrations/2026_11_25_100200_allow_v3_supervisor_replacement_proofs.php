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
        // Preserve existing proofs and add the supporting FK index before
        // dropping the previous unique index (required on MySQL).
        Schema::table('cleaning_v3_supervisor_attendance_proofs', function (Blueprint $table): void {
            $table->unique(['cleaning_booking_session_id', 'supervisor_assignment_id'], 'cv3_sa_visit_assignment_uq');
        });
        Schema::table('cleaning_v3_supervisor_attendance_proofs', function (Blueprint $table): void {
            $table->dropUnique('cv3_sa_visit_uq');
        });
    }

    public function down(): void
    {
        if (DB::table('cleaning_v3_supervisor_attendance_proofs')->select('cleaning_booking_session_id')
            ->groupBy('cleaning_booking_session_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot collapse historical supervisor attendance proofs.');
        }
        Schema::table('cleaning_v3_supervisor_attendance_proofs', function (Blueprint $table): void {
            $table->unique('cleaning_booking_session_id', 'cv3_sa_visit_uq');
        });
        Schema::table('cleaning_v3_supervisor_attendance_proofs', function (Blueprint $table): void {
            $table->dropUnique('cv3_sa_visit_assignment_uq');
        });
    }
};
