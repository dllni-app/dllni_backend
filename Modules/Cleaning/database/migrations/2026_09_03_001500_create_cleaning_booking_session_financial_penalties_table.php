<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cleaning_booking_session_financial_penalties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cleaning_booking_id')->constrained('cleaning_bookings')->cascadeOnDelete();
            $table->foreignId('cleaning_booking_session_id')
                ->constrained('cleaning_booking_sessions')
                ->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('workers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('financial_transaction_id')
                ->nullable()
                ->constrained('cleaning_deposit_transactions')
                ->nullOnDelete();
            $table->string('reference_key', 160)->unique();
            $table->string('penalized_role', 20);
            $table->string('financial_source', 30);
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('active');
            $table->text('reason_snapshot')->nullable();
            $table->timestamp('applied_at');
            $table->timestamps();

            $table->index(
                ['cleaning_booking_session_id', 'penalized_role', 'status'],
                'cleaning_session_penalty_session_role_idx',
            );
            $table->index(
                ['cleaning_booking_id', 'status'],
                'cleaning_session_penalty_booking_status_idx',
            );
            $table->index(['worker_id', 'status'], 'cleaning_session_penalty_worker_status_idx');
            $table->index(['customer_id', 'status'], 'cleaning_session_penalty_customer_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_booking_session_financial_penalties');
    }
};
