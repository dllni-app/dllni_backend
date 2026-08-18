<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('cleaning_bookings', 'cancelled_by_worker_id')) {
            Schema::table('cleaning_bookings', function (Blueprint $table): void {
                $table->foreignId('cancelled_by_worker_id')
                    ->nullable()
                    ->after('cancelled_by_role')
                    ->constrained('workers')
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('cleaning_bookings', 'cancellation_offset_minutes')) {
            Schema::table('cleaning_bookings', function (Blueprint $table): void {
                $table->integer('cancellation_offset_minutes')
                    ->nullable()
                    ->after('cancelled_by_worker_id');
            });
        }

        if (! Schema::hasColumn('cleaning_booking_worker_assignments', 'status_before_booking_cancellation')) {
            Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
                $table->string('status_before_booking_cancellation')
                    ->nullable()
                    ->after('status');
            });
        }

        if (! Schema::hasColumn('cleaning_booking_worker_assignments', 'booking_cancelled_at')) {
            Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
                $table->timestamp('booking_cancelled_at')
                    ->nullable()
                    ->after('status_before_booking_cancellation');
            });
        }

        if (! Schema::hasColumn('cleaning_booking_worker_assignments', 'cancelled_by_this_worker')) {
            Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
                $table->boolean('cancelled_by_this_worker')
                    ->default(false)
                    ->after('booking_cancelled_at');
            });
        }
    }

    public function down(): void
    {
        // Intentionally left empty. This is a repair migration for production
        // databases that may have an inconsistent migration history. Rolling it
        // back must not remove columns that could have been created by the
        // original 2026_07_24 migration.
    }
};
