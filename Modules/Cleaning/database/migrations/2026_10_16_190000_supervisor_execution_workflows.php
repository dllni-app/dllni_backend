<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_supervisor_verifications', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cleaning_booking_id')->unique()->constrained('cleaning_bookings')->cascadeOnDelete();
            $t->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('code_hash');
            $t->text('customer_code_ciphertext');
            $t->string('status', 32)->default('pending');
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->dateTime('expires_at');
            $t->dateTime('verified_at')->nullable();
            $t->timestamps();
        });
        Schema::create('cleaning_supervisor_extensions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $t->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('requested_minutes');
            $t->text('reason');
            $t->string('status', 32)->default('pending');
            $t->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('decision_note')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->timestamps();
            $t->index(['cleaning_booking_id', 'status'], 'cs_extension_booking_status_idx');
        });
        Schema::create('cleaning_supervisor_handovers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cleaning_booking_id')->unique()->constrained('cleaning_bookings')->cascadeOnDelete();
            $t->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('status', 32)->default('pending');
            $t->text('customer_note')->nullable();
            $t->dateTime('responded_at')->nullable();
            $t->timestamps();
        });
        Schema::create('cleaning_supervisor_reports', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cleaning_booking_id')->unique()->constrained('cleaning_bookings')->cascadeOnDelete();
            $t->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $t->text('summary');
            $t->text('quality_notes')->nullable();
            $t->dateTime('submitted_at');
            $t->timestamps();
        });
        Schema::create('cleaning_supervisor_media', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $t->foreignId('supervisor_user_id')->constrained('users')->restrictOnDelete();
            $t->string('purpose', 32);
            $t->string('disk', 24)->default('local');
            $t->string('path');
            $t->string('mime_type', 100);
            $t->unsignedInteger('bytes');
            $t->string('sha256', 64);
            $t->timestamps();
            $t->index(['cleaning_booking_id', 'purpose'], 'cs_media_booking_purpose_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_supervisor_media');
        Schema::dropIfExists('cleaning_supervisor_reports');
        Schema::dropIfExists('cleaning_supervisor_handovers');
        Schema::dropIfExists('cleaning_supervisor_extensions');
        Schema::dropIfExists('cleaning_supervisor_verifications');
    }
};
