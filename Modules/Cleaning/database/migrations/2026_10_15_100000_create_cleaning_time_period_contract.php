<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_booking_rooms', function (Blueprint $table): void {
            $table->string('client_key', 64)->nullable();
            $table->string('cleaning_mode', 16)->nullable();
            $table->unique(['cleaning_booking_id', 'client_key'], 'cbr_client_unique');
        });
        Schema::create('cleaning_booking_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained('users', 'id', 'cbq_user_fk')->cascadeOnDelete();
            $table->string('request_hash', 64);
            $table->string('signature', 64);
            $table->json('snapshot');
            $table->timestamp('expires_at');
            $table->uuid('idempotency_key')->nullable();
            $table->foreignId('cleaning_booking_id')->nullable()->constrained('cleaning_bookings', 'id', 'cbq_booking_fk')->nullOnDelete();
            $table->timestamps();
            $table->unique(['customer_id', 'idempotency_key'], 'cbq_retry_unique');
        });
        Schema::create('cleaning_room_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings', 'id', 'crs_booking_fk')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_session_id')->constrained('cleaning_booking_sessions', 'id', 'crs_visit_fk')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_room_id')->constrained('cleaning_booking_rooms', 'id', 'crs_room_fk')->cascadeOnDelete();
            $table->string('schedule_key', 64);
            $table->string('visit_key', 64);
            $table->string('timezone', 64);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->unsignedSmallInteger('required_workers');
            $table->unsignedInteger('work_minutes');
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->unique(['cleaning_booking_session_id', 'schedule_key'], 'crs_key_unique');
            $table->unique(['cleaning_booking_session_id', 'cleaning_booking_room_id'], 'crs_room_unique');
            $table->index(['cleaning_booking_id', 'starts_at', 'ends_at'], 'crs_interval_idx');
        });
        Schema::create('cleaning_time_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings', 'id', 'ctp_booking_fk')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_session_id')->constrained('cleaning_booking_sessions', 'id', 'ctp_visit_fk')->cascadeOnDelete();
            $table->string('period_key', 64);
            $table->string('timezone', 64);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('materials_choice', 32);
            $table->json('lineage')->nullable();
            $table->timestamps();
            $table->unique(['cleaning_booking_session_id', 'period_key'], 'ctp_key_unique');
        });
        Schema::create('cleaning_time_period_room_schedules', function (Blueprint $table): void {
            $table->foreignId('cleaning_time_period_id')->constrained('cleaning_time_periods', 'id', 'ctpm_period_fk')->cascadeOnDelete();
            $table->foreignId('cleaning_room_schedule_id')->constrained('cleaning_room_schedules', 'id', 'ctpm_schedule_fk')->cascadeOnDelete();
            $table->primary(['cleaning_time_period_id', 'cleaning_room_schedule_id'], 'ctpm_primary');
            $table->unique('cleaning_room_schedule_id', 'ctpm_allocation_unique');
        });
        Schema::create('cleaning_time_period_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_time_period_id')->constrained('cleaning_time_periods', 'id', 'ctpmat_period_fk')->cascadeOnDelete();
            $table->foreignId('cleaning_material_type_id')->constrained('cleaning_material_types', 'id', 'ctpmat_type_fk');
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 64);
            $table->json('snapshot');
            $table->unique(['cleaning_time_period_id', 'cleaning_material_type_id'], 'ctpmat_unique');
        });
        Schema::create('cleaning_time_period_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_time_period_id')->constrained('cleaning_time_periods', 'id', 'ctps_period_fk')->cascadeOnDelete();
            $table->foreignId('cleaning_room_schedule_id')->constrained('cleaning_room_schedules', 'id', 'ctps_schedule_fk')->cascadeOnDelete();
            $table->foreignId('cleaning_special_service_id')->constrained('cleaning_special_services', 'id', 'ctps_catalog_fk');
            $table->foreignId('cleaning_booking_special_service_item_id')->nullable()->constrained('cleaning_booking_special_service_items', 'id', 'ctps_item_fk')->nullOnDelete();
            $table->string('service_key', 64);
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 64);
            $table->string('dirtiness_level', 16)->nullable();
            $table->json('snapshot');
            $table->unique(['cleaning_time_period_id', 'service_key', 'cleaning_room_schedule_id'], 'ctps_target_unique');
        });
    }

    public function down(): void
    {
        foreach (['cleaning_time_period_services', 'cleaning_time_period_materials', 'cleaning_time_period_room_schedules', 'cleaning_time_periods', 'cleaning_room_schedules', 'cleaning_booking_quotes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('cleaning_booking_rooms', function (Blueprint $table): void {
            $table->dropUnique('cbr_client_unique');
            $table->dropColumn(['client_key', 'cleaning_mode']);
        });
    }
};
