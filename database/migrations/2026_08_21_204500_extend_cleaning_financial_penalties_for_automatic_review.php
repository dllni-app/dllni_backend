<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_financial_penalties', function (Blueprint $table): void {
            $table->foreignId('worker_id')->nullable()->change();
            $table->foreignId('customer_id')->nullable()->after('worker_id')->constrained('users')->nullOnDelete();
            $table->string('penalized_role', 20)->default('worker')->after('customer_id');
            $table->string('review_status', 20)->default('needs_review')->after('status');
            $table->foreignId('reviewed_by_admin_id')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_admin_id');
            $table->foreignId('cancelled_by_admin_id')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('penalty_cancelled_at')->nullable()->after('cancelled_by_admin_id');
            $table->text('penalty_cancellation_note')->nullable()->after('penalty_cancelled_at');

            $table->index(['penalized_role', 'review_status'], 'cleaning_penalties_role_review_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_financial_penalties', function (Blueprint $table): void {
            $table->dropIndex('cleaning_penalties_role_review_idx');
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('reviewed_by_admin_id');
            $table->dropConstrainedForeignId('cancelled_by_admin_id');
            $table->dropColumn([
                'penalized_role',
                'review_status',
                'reviewed_at',
                'penalty_cancelled_at',
                'penalty_cancellation_note',
            ]);
            $table->foreignId('worker_id')->nullable(false)->change();
        });
    }
};
