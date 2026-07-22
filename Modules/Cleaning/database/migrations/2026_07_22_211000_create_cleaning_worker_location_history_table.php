<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cleaning_worker_location_history')) {
            return;
        }

        Schema::create('cleaning_worker_location_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->foreignId('assignment_id')
                ->nullable()
                ->constrained('cleaning_booking_worker_assignments')
                ->nullOnDelete();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(
                ['cleaning_booking_id', 'recorded_at'],
                'cwlh_booking_recorded_at_idx',
            );
            $table->index(
                ['worker_id', 'recorded_at'],
                'cwlh_worker_recorded_at_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_worker_location_history');
    }
};
