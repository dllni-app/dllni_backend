<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cleaning_booking_room_periods')) {
            return;
        }

        Schema::create('cleaning_booking_room_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings', 'id', 'cbrp_booking_fk')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_session_id')->nullable()
                ->constrained('cleaning_booking_sessions', 'id', 'cbrp_session_fk')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_room_id')->nullable()
                ->constrained('cleaning_booking_rooms', 'id', 'cbrp_room_fk')->nullOnDelete();
            // Stable client key: roomType.index; not the legacy roomType.size.index.
            $table->string('client_key', 64);
            $table->unsignedSmallInteger('visit_sequence')->default(1);
            $table->string('room_type', 32);
            $table->string('room_size', 16);
            $table->string('cleaning_mode', 16);
            $table->date('scheduled_date');
            $table->time('scheduled_time');
            $table->unsignedSmallInteger('required_workers');
            $table->unsignedInteger('estimated_work_minutes')->nullable();
            $table->unsignedInteger('estimated_duration_minutes')->nullable();
            $table->json('materials')->nullable();
            $table->json('special_services')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('base_price', 12, 2)->nullable();
            $table->decimal('addons_total', 12, 2)->nullable();
            $table->decimal('total_price', 12, 2)->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['cleaning_booking_id', 'visit_sequence', 'client_key'], 'cleaning_room_period_identity_unique');
            $table->index(['cleaning_booking_id', 'scheduled_date', 'scheduled_time'], 'cleaning_room_period_schedule_idx');
            $table->index(['cleaning_booking_session_id', 'scheduled_date'], 'cleaning_room_period_visit_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_room_periods');
    }
};
