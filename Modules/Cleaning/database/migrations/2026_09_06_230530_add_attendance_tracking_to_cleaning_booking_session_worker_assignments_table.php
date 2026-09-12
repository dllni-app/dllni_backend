<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_booking_session_worker_assignments', function (Blueprint $table): void {
            $table->timestamp('late_reported_at')->nullable()->after('released_reason');
            $table->timestamp('no_travel_reported_at')->nullable()->after('late_reported_at');
            $table->string('attendance_action')->nullable()->after('no_travel_reported_at');
            $table->timestamp('attendance_resolved_at')->nullable()->after('attendance_action');
            $table->text('attendance_note')->nullable()->after('attendance_resolved_at');

            $table->index('late_reported_at', 'cleaning_session_assignment_late_idx');
            $table->index('no_travel_reported_at', 'cleaning_session_assignment_no_travel_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_session_worker_assignments', function (Blueprint $table): void {
            $table->dropIndex('cleaning_session_assignment_late_idx');
            $table->dropIndex('cleaning_session_assignment_no_travel_idx');
            $table->dropColumn([
                'late_reported_at',
                'no_travel_reported_at',
                'attendance_action',
                'attendance_resolved_at',
                'attendance_note',
            ]);
        });
    }
};
