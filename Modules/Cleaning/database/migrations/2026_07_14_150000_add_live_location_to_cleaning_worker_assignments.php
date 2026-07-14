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
            $table->decimal('last_latitude', 10, 7)->nullable()->after('arrived_at');
            $table->decimal('last_longitude', 10, 7)->nullable()->after('last_latitude');
            $table->timestamp('location_updated_at')->nullable()->after('last_longitude');
            $table->index(
                ['cleaning_booking_id', 'location_updated_at'],
                'cbwa_booking_location_updated_idx',
            );
        });

        // Legacy single-worker bookings may not have an assignment row. Keep a
        // booking-level snapshot so their existing tracking flow also survives
        // page reloads and temporary realtime disconnects.
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->decimal('last_worker_latitude', 10, 7)->nullable()->after('arrived_at');
            $table->decimal('last_worker_longitude', 10, 7)->nullable()->after('last_worker_latitude');
            $table->timestamp('worker_location_updated_at')->nullable()->after('last_worker_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $table->dropIndex('cbwa_booking_location_updated_idx');
            $table->dropColumn([
                'last_latitude',
                'last_longitude',
                'location_updated_at',
            ]);
        });

        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn([
                'last_worker_latitude',
                'last_worker_longitude',
                'worker_location_updated_at',
            ]);
        });
    }
};
