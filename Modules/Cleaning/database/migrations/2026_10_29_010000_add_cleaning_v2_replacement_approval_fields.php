<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive change: the original request table was already applied to the
 * existing local QA MySQL database before multi-stage approval was designed.
 * Keep the original migration immutable so upgrading a populated database
 * does not silently omit the new approval and worker opt-in columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_booking_session_replacement_requests', static function (Blueprint $table): void {
            $table->foreignId('approved_by_user_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('candidate_worker_id')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('replacement_worker_assignment_id')->nullable();
            $table->foreignId('reallocated_by_user_id')->nullable();
            $table->timestamp('reallocated_at')->nullable();
            $table->foreign('approved_by_user_id', 'cbrr_approved_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('candidate_worker_id', 'cbrr_candidate_fk')->references('id')->on('workers')->restrictOnDelete();
            $table->foreign('accepted_by_user_id', 'cbrr_accepted_by_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('replacement_worker_assignment_id', 'cbrr_replacement_fk')->references('id')->on('cleaning_booking_session_worker_assignments')->restrictOnDelete();
            $table->foreign('reallocated_by_user_id', 'cbrr_reallocated_by_fk')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_session_replacement_requests', static function (Blueprint $table): void {
            $table->dropForeign('cbrr_approved_by_fk');
            $table->dropForeign('cbrr_candidate_fk');
            $table->dropForeign('cbrr_accepted_by_fk');
            $table->dropForeign('cbrr_replacement_fk');
            $table->dropForeign('cbrr_reallocated_by_fk');
            $table->dropColumn([
                'approved_by_user_id', 'approved_at', 'candidate_worker_id',
                'accepted_by_user_id', 'accepted_at', 'replacement_worker_assignment_id',
                'reallocated_by_user_id', 'reallocated_at',
            ]);
        });
    }
};
