<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This migration can become newly pending after merging dev with V2 even
        // when the V2 assignment table already exists. Never drop an existing
        // table here: doing so would destroy live session-assignment data.
        if (Schema::hasTable('cleaning_booking_session_worker_assignments')) {
            return;
        }

        Schema::create('cleaning_booking_session_worker_assignments', function (Blueprint $table): void {
            $table->id();

            // Explicit short FK names are required because MySQL limits
            // identifiers (including constraint names) to 64 characters.
            $table->unsignedBigInteger('cleaning_booking_session_id');
            $table->unsignedBigInteger('cleaning_booking_worker_assignment_id')->nullable();
            $table->unsignedBigInteger('worker_id');

            $table->foreign('cleaning_booking_session_id', 'cb_swa_session_fk')
                ->references('id')
                ->on('cleaning_booking_sessions')
                ->cascadeOnDelete();

            $table->foreign('cleaning_booking_worker_assignment_id', 'cb_swa_parent_fk')
                ->references('id')
                ->on('cleaning_booking_worker_assignments')
                ->nullOnDelete();

            $table->foreign('worker_id', 'cb_swa_worker_fk')
                ->references('id')
                ->on('workers')
                ->cascadeOnDelete();

            $table->string('status')->default('accepted_waiting_for_order_start');

            $table->timestamp('started_travel_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->decimal('last_latitude', 10, 7)->nullable();
            $table->decimal('last_longitude', 10, 7)->nullable();
            $table->timestamp('location_updated_at')->nullable();
            $table->timestamp('start_approved_at')->nullable();
            $table->timestamp('work_started_at')->nullable();
            $table->timestamp('work_finished_at')->nullable();
            $table->text('worker_completion_message')->nullable();

            $table->decimal('service_share_amount', 12, 2)->default(0);
            $table->decimal('travel_fee', 12, 2)->default(0);
            $table->decimal('admin_margin_amount', 12, 2)->default(0);
            $table->decimal('worker_amount', 12, 2)->default(0);
            $table->string('currency', 8)->default('SYP');
            $table->timestamps();

            $table->unique(['cleaning_booking_session_id', 'worker_id'], 'cleaning_session_worker_unique');
            $table->index(['worker_id', 'status'], 'cleaning_session_worker_status_idx');
            $table->index('cleaning_booking_worker_assignment_id', 'cleaning_session_parent_assignment_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cleaning_booking_session_worker_assignments')) {
            return;
        }

        // accepted_at belongs to the richer V2 schema. Its presence means this
        // migration may have been a no-op against an existing V2 table.
        if (Schema::hasColumn('cleaning_booking_session_worker_assignments', 'accepted_at')) {
            return;
        }

        Schema::dropIfExists('cleaning_booking_session_worker_assignments');
    }
};
