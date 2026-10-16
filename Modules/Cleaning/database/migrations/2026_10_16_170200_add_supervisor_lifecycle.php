<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->string('supervised_state', 40)->nullable();
            $table->unsignedInteger('supervised_base_minutes')->nullable();
            $table->unsignedInteger('supervised_duration_minutes')->nullable();
            $table->dateTime('supervised_scheduled_end_at')->nullable();
            $table->dateTime('supervised_original_end_at')->nullable();
            $table->timestamp('supervised_verified_at')->nullable();
            $table->index(['booking_kind', 'supervised_state', 'scheduled_date'], 'superv_book_index');
        });

        Schema::table('cleaning_supervisor_replacements', function (Blueprint $table): void {
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
        });

        Schema::create('cleaning_supervisor_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->unique()->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('code_hash', 64);
            $table->text('encrypted_code');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cleaning_supervisor_extensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('additional_minutes');
            $table->string('customer_decision', 24)->nullable();
            $table->timestamp('customer_decided_at')->nullable();
            $table->string('reason', 500);
            $table->string('status', 32)->default('pending');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cleaning_supervisor_handovers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->unique()->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('pending');
            $table->text('objection')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cleaning_supervisor_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->unique()->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $table->text('narrative');
            $table->timestamp('submitted_at');
            $table->timestamps();
        });

        Schema::create('cleaning_supervisor_media', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('incident_id')->nullable()->constrained('cleaning_supervisor_incidents')->nullOnDelete();
            $table->string('purpose', 16);
            $table->string('disk', 32);
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('bytes');
            $table->timestamps();
            $table->index(['cleaning_booking_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_supervisor_replacements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('decided_by_user_id');
            $table->dropColumn('decided_at');
        });
        foreach (['cleaning_supervisor_media','cleaning_supervisor_reports','cleaning_supervisor_handovers',
                  'cleaning_supervisor_extensions','cleaning_supervisor_verifications'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropIndex('superv_book_index');
            $table->dropColumn(['supervised_state','supervised_base_minutes','supervised_duration_minutes',
                'supervised_scheduled_end_at','supervised_original_end_at','supervised_verified_at']);
        });
    }
};
