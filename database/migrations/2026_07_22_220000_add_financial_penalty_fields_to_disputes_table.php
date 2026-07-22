<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disputes', function (Blueprint $table): void {
            $table->foreignId('financial_penalty_worker_id')
                ->nullable()
                ->after('worker_earnings_frozen')
                ->constrained('workers')
                ->nullOnDelete();
            $table->decimal('financial_penalty_amount', 12, 2)
                ->nullable()
                ->after('financial_penalty_worker_id');
            $table->text('financial_penalty_notes')
                ->nullable()
                ->after('financial_penalty_amount');
            $table->foreignId('financial_penalty_transaction_id')
                ->nullable()
                ->after('financial_penalty_notes')
                ->constrained('cleaning_deposit_transactions')
                ->nullOnDelete();
            $table->foreignId('financial_penalty_applied_by')
                ->nullable()
                ->after('financial_penalty_transaction_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('financial_penalty_applied_at')
                ->nullable()
                ->after('financial_penalty_applied_by');
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('financial_penalty_worker_id');
            $table->dropConstrainedForeignId('financial_penalty_transaction_id');
            $table->dropConstrainedForeignId('financial_penalty_applied_by');
            $table->dropColumn([
                'financial_penalty_amount',
                'financial_penalty_notes',
                'financial_penalty_applied_at',
            ]);
        });
    }
};
