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
            $table->string('preferred_worker_rejection_decision_status', 40)
                ->nullable()
                ->after('converted_from_preferred_worker_at');
            $table->timestamp('preferred_worker_rejected_at')
                ->nullable()
                ->after('preferred_worker_rejection_decision_status');
            $table->foreignId('preferred_worker_rejection_worker_id')
                ->nullable()
                ->after('preferred_worker_rejected_at')
                ->constrained('workers')
                ->nullOnDelete();
            $table->timestamp('preferred_worker_rejection_decided_at')
                ->nullable()
                ->after('preferred_worker_rejection_worker_id');

            $table->index(
                ['customer_id', 'preferred_worker_rejection_decision_status'],
                'cb_pref_rejection_customer_status_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropIndex('cb_pref_rejection_customer_status_idx');
            $table->dropConstrainedForeignId('preferred_worker_rejection_worker_id');
            $table->dropColumn([
                'preferred_worker_rejection_decision_status',
                'preferred_worker_rejected_at',
                'preferred_worker_rejection_decided_at',
            ]);
        });
    }
};
