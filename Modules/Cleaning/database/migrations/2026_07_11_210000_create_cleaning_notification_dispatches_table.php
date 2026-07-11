<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_notification_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')
                ->constrained('cleaning_bookings')
                ->cascadeOnDelete();
            $table->foreignId('worker_assignment_id')
                ->nullable()
                ->constrained('cleaning_booking_worker_assignments')
                ->nullOnDelete();
            $table->foreignId('recipient_user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('canonical_type', 160);
            $table->string('dedupe_key', 191)->unique();
            $table->timestamp('scheduled_at_snapshot');
            $table->timestamp('due_at');
            $table->string('status', 24)->default('claimed');
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['cleaning_booking_id', 'canonical_type'], 'cleaning_notification_booking_type_idx');
            $table->index(['status', 'due_at'], 'cleaning_notification_status_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_notification_dispatches');
    }
};
