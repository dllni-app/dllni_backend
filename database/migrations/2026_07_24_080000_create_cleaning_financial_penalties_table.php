<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_financial_penalties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->unique()->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('workers')->restrictOnDelete();
            $table->foreignId('financial_transaction_id')->nullable()->unique()->constrained('cleaning_deposit_transactions')->nullOnDelete();
            $table->string('financial_source', 20);
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('active');
            $table->text('notes');
            $table->text('cancellation_reason_snapshot')->nullable();
            $table->integer('cancellation_offset_minutes')->nullable();
            $table->foreignId('applied_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at');
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();

            $table->index(['worker_id', 'status']);
            $table->index(['financial_source', 'status']);
            $table->index('applied_at');
        });

        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->foreignId('cancelled_by_worker_id')->nullable()->after('cancelled_by_role')->constrained('workers')->nullOnDelete();
            $table->integer('cancellation_offset_minutes')->nullable()->after('cancelled_by_worker_id');
        });

        Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $table->string('status_before_booking_cancellation')->nullable()->after('status');
            $table->timestamp('booking_cancelled_at')->nullable()->after('status_before_booking_cancellation');
            $table->boolean('cancelled_by_this_worker')->default(false)->after('booking_cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_booking_worker_assignments', function (Blueprint $table): void {
            $table->dropColumn(['status_before_booking_cancellation', 'booking_cancelled_at', 'cancelled_by_this_worker']);
        });

        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by_worker_id');
            $table->dropColumn('cancellation_offset_minutes');
        });

        Schema::dropIfExists('cleaning_financial_penalties');
    }
};
