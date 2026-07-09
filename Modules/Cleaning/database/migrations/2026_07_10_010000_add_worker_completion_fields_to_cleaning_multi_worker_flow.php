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
            if (! Schema::hasColumn('cleaning_booking_worker_assignments', 'worker_finished_cleaning_services')) {
                $table->json('worker_finished_cleaning_services')->nullable()->after('worker_completion_message');
            }

            if (! Schema::hasColumn('cleaning_booking_worker_assignments', 'worker_finished_property_rooms')) {
                $table->json('worker_finished_property_rooms')->nullable()->after('worker_finished_cleaning_services');
            }
        });

        Schema::table('cleaning_time_warnings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_time_warnings', 'worker_id')) {
                $table->unsignedBigInteger('worker_id')->nullable()->after('booking_type');
                $table->index(['booking_id', 'booking_type', 'worker_id'], 'ctw_booking_worker_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_time_warnings', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_time_warnings', 'worker_id')) {
                $table->dropIndex('ctw_booking_worker_idx');
                $table->dropColumn('worker_id');
            }
        });

        Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('cleaning_booking_worker_assignments', 'worker_finished_property_rooms')) {
                $columns[] = 'worker_finished_property_rooms';
            }

            if (Schema::hasColumn('cleaning_booking_worker_assignments', 'worker_finished_cleaning_services')) {
                $columns[] = 'worker_finished_cleaning_services';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
