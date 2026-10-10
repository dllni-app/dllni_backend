<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cleaning_operational_action_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('action', 60);
            $table->foreignId('cleaning_booking_id')->nullable()->constrained('cleaning_bookings')->nullOnDelete();
            $table->foreignId('cleaning_booking_session_id')->nullable()->constrained('cleaning_booking_sessions')->nullOnDelete();
            $table->foreignId('cleaning_equipment_reservation_id')->nullable()->constrained('cleaning_equipment_reservations')->nullOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['action', 'occurred_at'], 'clean_ops_audits_action_time');
        });

        Schema::table('cleaning_equipment_reservations', function (Blueprint $table): void {
            $table->foreignId('handed_over_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('return_confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('return_confirmed_at')->nullable();
            $table->string('return_resolution', 24)->nullable();
            $table->text('return_admin_note')->nullable();
        });
        Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
            $table->timestamp('open_time_terminated_at')->nullable();
            $table->foreignId('open_time_terminated_by_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('open_time_terminated_by_id');
            $table->dropColumn('open_time_terminated_at');
        });
        Schema::table('cleaning_equipment_reservations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('handed_over_by_user_id');
            $table->dropConstrainedForeignId('return_confirmed_by_user_id');
            $table->dropColumn(['return_confirmed_at', 'return_resolution', 'return_admin_note']);
        });
        Schema::dropIfExists('cleaning_operational_action_audits');
    }
};
