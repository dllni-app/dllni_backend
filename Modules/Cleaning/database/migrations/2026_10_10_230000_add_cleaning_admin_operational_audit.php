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
            $table->foreignId('cleaning_booking_id')->nullable()->constrained('cleaning_bookings', 'id', 'clean_ops_audit_booking_fk')->nullOnDelete();
            $table->foreignId('cleaning_booking_session_id')->nullable()->constrained('cleaning_booking_sessions', 'id', 'clean_ops_audit_session_fk')->nullOnDelete();
            $table->foreignId('cleaning_equipment_reservation_id')->nullable()->constrained('cleaning_equipment_reservations', 'id', 'clean_ops_audit_reservation_fk')->nullOnDelete();
            $table->foreignId('actor_user_id')->constrained('users', 'id', 'clean_ops_audit_actor_fk')->restrictOnDelete();
            $table->text('reason')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['action', 'occurred_at'], 'clean_ops_audits_action_time');
        });

        Schema::table('cleaning_equipment_reservations', function (Blueprint $table): void {
            $table->foreignId('handed_over_by_user_id')->nullable()->constrained('users', 'id', 'clean_equip_handover_actor_fk')->nullOnDelete();
            $table->foreignId('return_confirmed_by_user_id')->nullable()->constrained('users', 'id', 'clean_equip_return_actor_fk')->nullOnDelete();
            $table->timestamp('return_confirmed_at')->nullable();
            $table->string('return_resolution', 24)->nullable();
            $table->text('return_admin_note')->nullable();
        });
        Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
            $table->timestamp('open_time_terminated_at')->nullable();
            $table->foreignId('open_time_terminated_by_id')->nullable()->constrained('users', 'id', 'clean_session_terminated_actor_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
            $table->dropForeign('clean_session_terminated_actor_fk');
            $table->dropColumn('open_time_terminated_by_id');
            $table->dropColumn('open_time_terminated_at');
        });
        Schema::table('cleaning_equipment_reservations', function (Blueprint $table): void {
            $table->dropForeign('clean_equip_handover_actor_fk');
            $table->dropForeign('clean_equip_return_actor_fk');
            $table->dropColumn(['handed_over_by_user_id', 'return_confirmed_by_user_id']);
            $table->dropColumn(['return_confirmed_at', 'return_resolution', 'return_admin_note']);
        });
        Schema::dropIfExists('cleaning_operational_action_audits');
    }
};
