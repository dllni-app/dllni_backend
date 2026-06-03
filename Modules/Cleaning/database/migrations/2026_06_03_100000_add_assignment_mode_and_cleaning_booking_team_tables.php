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
            $table->string('assignment_mode')->nullable()->after('preferred_worker_id');
        });

        Schema::create('cleaning_booking_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->string('room_key');
            $table->string('room_type');
            $table->string('room_size')->nullable();
            $table->string('display_label');
            $table->decimal('weight', 8, 2)->default(0);
            $table->foreignId('assigned_worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->string('assignment_source')->nullable();
            $table->timestamps();

            $table->unique(['cleaning_booking_id', 'room_key']);
            $table->index(['cleaning_booking_id', 'assigned_worker_id']);
            $table->index(['cleaning_booking_id', 'room_type']);
        });

        Schema::create('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('workers')->cascadeOnDelete();
            $table->string('status')->default('accepted');
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedInteger('room_count')->default(0);
            $table->decimal('rooms_weight', 10, 2)->default(0);
            $table->decimal('service_share_amount', 12, 2)->default(0);
            $table->decimal('travel_fee', 12, 2)->default(0);
            $table->decimal('admin_margin_amount', 12, 2)->default(0);
            $table->decimal('worker_amount', 12, 2)->default(0);
            $table->string('currency', 8)->default('SYP');
            $table->timestamps();

            $table->unique(['cleaning_booking_id', 'worker_id']);
            $table->index(['cleaning_booking_id', 'status']);
            $table->index(['worker_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_worker_assignments');
        Schema::dropIfExists('cleaning_booking_rooms');

        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropColumn('assignment_mode');
        });
    }
};
