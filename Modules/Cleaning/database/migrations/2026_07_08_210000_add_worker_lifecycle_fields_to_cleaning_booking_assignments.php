<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $table->timestamp('started_travel_at')->nullable()->after('accepted_at');
            $table->timestamp('arrived_at')->nullable()->after('started_travel_at');
            $table->timestamp('work_started_at')->nullable()->after('start_approved_at');
            $table->timestamp('work_finished_at')->nullable()->after('work_started_at');
            $table->text('worker_completion_message')->nullable()->after('work_finished_at');

            $table->index(['cleaning_booking_id', 'worker_id', 'status'], 'cbwa_booking_worker_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $table->dropIndex('cbwa_booking_worker_status_idx');
            $table->dropColumn([
                'started_travel_at',
                'arrived_at',
                'work_started_at',
                'work_finished_at',
                'worker_completion_message',
            ]);
        });
    }
};
