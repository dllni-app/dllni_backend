<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
                ->references('id')
                ->on('cleaning_bookings')
                ->cascadeOnDelete();

            $table->foreign('cleaning_booking_worker_assignment_id', 'cbwlp_assignment_fk')
                ->references('id')
                ->on('cleaning_booking_worker_assignments')
                ->nullOnDelete();

            $table->foreign('worker_id', 'cbwlp_worker_fk')
                ->references('id')
                ->on('workers')
                ->cascadeOnDelete();

            $table->index(
                ['cleaning_booking_id', 'worker_id', 'recorded_at'],
                'cbwlp_booking_worker_recorded_idx',
            );
            $table->index(
                ['cleaning_booking_worker_assignment_id', 'recorded_at'],
                'cbwlp_assignment_recorded_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_worker_location_points');
    }
};
