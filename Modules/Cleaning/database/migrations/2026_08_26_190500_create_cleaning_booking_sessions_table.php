<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This migration was introduced on dev before the V2 session migration.
        // After the branches were merged, some databases may already contain the
        // richer V2 table while this migration is still pending. Never recreate
        // or replace an existing table in that case.
        if (Schema::hasTable('cleaning_booking_sessions')) {
            return;
        }

        Schema::create('cleaning_booking_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('scheduled_date');
            $table->time('scheduled_time');
            $table->decimal('duration_hours', 8, 2);
            $table->string('status')->default('scheduled');

            $table->decimal('base_price', 12, 2)->default(0);
            $table->decimal('travel_fee', 12, 2)->default(0);
            $table->decimal('travel_distance_km', 10, 3)->nullable();
            $table->decimal('admin_margin_amount', 12, 2)->default(0);
            $table->decimal('extension_fee_total', 12, 2)->default(0);
            $table->decimal('cancellation_fee', 12, 2)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->boolean('is_pricing_final')->default(false);

            $table->timestamp('started_travel_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('customer_confirmed_at')->nullable();
            $table->timestamp('work_started_at')->nullable();
            $table->timestamp('work_finished_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->string('cancelled_by_role')->nullable();
            $table->timestamps();

            $table->unique(['cleaning_booking_id', 'sequence'], 'cleaning_booking_session_sequence_unique');
            $table->unique(['cleaning_booking_id', 'scheduled_date', 'scheduled_time'], 'cleaning_booking_session_slot_unique');
            $table->index(['scheduled_date', 'status'], 'cleaning_booking_session_date_status_idx');
            $table->index(['cleaning_booking_id', 'status'], 'cleaning_booking_session_booking_status_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cleaning_booking_sessions')) {
            return;
        }

        // If the richer V2 signature is present, this migration may have been a
        // no-op on an already-upgraded database. Do not delete that shared table.
        if (Schema::hasColumn('cleaning_booking_sessions', 'session_type')) {
            return;
        }

        Schema::dropIfExists('cleaning_booking_sessions');
    }
};
