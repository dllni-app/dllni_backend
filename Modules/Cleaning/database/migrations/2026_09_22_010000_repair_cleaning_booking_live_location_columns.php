<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cleaning_bookings')) {
            return;
        }

        $addLatitude = ! Schema::hasColumn('cleaning_bookings', 'last_worker_latitude');
        $addLongitude = ! Schema::hasColumn('cleaning_bookings', 'last_worker_longitude');
        $addUpdatedAt = ! Schema::hasColumn('cleaning_bookings', 'worker_location_updated_at');

        if ($addLatitude || $addLongitude || $addUpdatedAt) {
            Schema::table('cleaning_bookings', function (Blueprint $table) use ($addLatitude, $addLongitude, $addUpdatedAt): void {
                if ($addLatitude) {
                    $table->decimal('last_worker_latitude', 10, 7)->nullable();
                }

                if ($addLongitude) {
                    $table->decimal('last_worker_longitude', 10, 7)->nullable();
                }

                if ($addUpdatedAt) {
                    $table->timestamp('worker_location_updated_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('cleaning_booking_worker_assignments')) {
            $addAssignmentLatitude = ! Schema::hasColumn('cleaning_booking_worker_assignments', 'last_latitude');
            $addAssignmentLongitude = ! Schema::hasColumn('cleaning_booking_worker_assignments', 'last_longitude');
            $addAssignmentUpdatedAt = ! Schema::hasColumn('cleaning_booking_worker_assignments', 'location_updated_at');

            if ($addAssignmentLatitude || $addAssignmentLongitude || $addAssignmentUpdatedAt) {
                Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table) use ($addAssignmentLatitude, $addAssignmentLongitude, $addAssignmentUpdatedAt): void {
                    if ($addAssignmentLatitude) {
                        $table->decimal('last_latitude', 10, 7)->nullable();
                    }

                    if ($addAssignmentLongitude) {
                        $table->decimal('last_longitude', 10, 7)->nullable();
                    }

                    if ($addAssignmentUpdatedAt) {
                        $table->timestamp('location_updated_at')->nullable();
                    }
                });
            }
        }

        if (
            ! Schema::hasTable('cleaning_booking_worker_location_points')
            && Schema::hasTable('cleaning_booking_worker_assignments')
            && Schema::hasTable('workers')
        ) {
            Schema::create('cleaning_booking_worker_location_points', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('cleaning_booking_id');
                $table->unsignedBigInteger('cleaning_booking_worker_assignment_id')->nullable();
                $table->unsignedBigInteger('worker_id');
                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->timestamp('recorded_at');
                $table->timestamps();

                $table->foreign('cleaning_booking_id', 'cbwlp_booking_fk')
                    ->references('id')->on('cleaning_bookings')->cascadeOnDelete();
                $table->foreign('cleaning_booking_worker_assignment_id', 'cbwlp_assignment_fk')
                    ->references('id')->on('cleaning_booking_worker_assignments')->nullOnDelete();
                $table->foreign('worker_id', 'cbwlp_worker_fk')
                    ->references('id')->on('workers')->cascadeOnDelete();
                $table->index(['cleaning_booking_id', 'worker_id', 'recorded_at'], 'cbwlp_booking_worker_recorded_idx');
                $table->index(['cleaning_booking_worker_assignment_id', 'recorded_at'], 'cbwlp_assignment_recorded_idx');
            });
        }
    }

    public function down(): void
    {
        // This is a schema-drift repair migration. Do not remove columns that may
        // have been created by the original live-location migration.
    }
};
