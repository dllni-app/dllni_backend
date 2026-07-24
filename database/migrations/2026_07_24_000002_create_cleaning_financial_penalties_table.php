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
            $table->enum('financial_source', ['deposit', 'debt']);
            $table->decimal('amount', 12, 2);
            $table->enum('status', ['active', 'cleared'])->default('active');
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
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_financial_penalties');
    }
};
