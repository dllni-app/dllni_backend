<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // V2 running time and amounts stay untouched. These records may only
        // be written by a future V3-only, authenticated, locked command handler.
        if (Schema::hasTable('cleaning_work_intervals')
            || Schema::hasTable('cleaning_work_pause_requests')) {
            throw new RuntimeException('Existing work clock tables require explicit schema reconciliation.');
        }
        if (! Schema::hasColumn('cleaning_booking_sessions', 'clock_revision')) {
            throw new RuntimeException('V3 visit identity migration must run before the work clock.');
        }

        Schema::create('cleaning_work_intervals', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_id')
                ->constrained('cleaning_booking_sessions', 'id', 'cv3_wi_session_fk')->restrictOnDelete();
            // NULL after closing. Unique across all visits also rules out
            // two open intervals for the same visit under concurrent writes.
            $table->unsignedBigInteger('active_session_id')->nullable()
                ->unique('cv3_wi_one_open_uq');
            $table->foreign('active_session_id', 'cv3_wi_open_session_fk')
                ->references('id')->on('cleaning_booking_sessions')->restrictOnDelete();
            $table->string('start_command_key', 96);
            $table->string('end_command_key', 96)->nullable();
            $table->unsignedInteger('opened_clock_revision');
            $table->unsignedInteger('closed_clock_revision')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['cleaning_booking_session_id', 'start_command_key'], 'cv3_wi_start_key_uq');
            $table->unique(['cleaning_booking_session_id', 'end_command_key'], 'cv3_wi_end_key_uq');
            $table->index(['cleaning_booking_session_id', 'started_at'], 'cv3_wi_visit_start_idx');
        });

        Schema::create('cleaning_work_pause_requests', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_session_id')
                ->constrained('cleaning_booking_sessions', 'id', 'cv3_wp_session_fk')->restrictOnDelete();
            // NULL when the request is decided or withdrawn.
            $table->unsignedBigInteger('pending_session_id')->nullable()
                ->unique('cv3_wp_one_pending_uq');
            $table->foreign('pending_session_id', 'cv3_wp_pending_session_fk')
                ->references('id')->on('cleaning_booking_sessions')->restrictOnDelete();
            $table->string('command_key', 96);
            $table->foreignId('requested_by')
                ->constrained('users', 'id', 'cv3_wp_requester_fk')->restrictOnDelete();
            $table->unsignedInteger('expected_clock_revision');
            $table->string('status', 24)->default('pending');
            $table->timestamp('requested_at');
            $table->foreignId('decided_by')->nullable()
                ->constrained('users', 'id', 'cv3_wp_decider_fk')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_command_key', 96)->nullable();
            $table->timestamps();

            $table->unique(['cleaning_booking_session_id', 'command_key'], 'cv3_wp_request_key_uq');
            $table->unique(['cleaning_booking_session_id', 'decision_command_key'], 'cv3_wp_decision_key_uq');
            $table->index(['cleaning_booking_session_id', 'status'], 'cv3_wp_visit_status_idx');
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('cleaning_work_intervals') && DB::table('cleaning_work_intervals')->exists())
            || (Schema::hasTable('cleaning_work_pause_requests') && DB::table('cleaning_work_pause_requests')->exists())) {
            throw new RuntimeException('Refusing to delete the V3 work-clock audit history.');
        }

        Schema::dropIfExists('cleaning_work_pause_requests');
        Schema::dropIfExists('cleaning_work_intervals');
    }
};
