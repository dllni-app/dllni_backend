<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
            $table->string('payment_status')->default('pending')->after('is_pricing_final');
            $table->timestamp('payment_settled_at')->nullable()->after('payment_status');
            $table->index(['payment_status', 'scheduled_date'], 'cleaning_session_payment_status_idx');
        });

        Schema::table('worker_customer_ratings', function (Blueprint $table): void {
            $table->foreignId('cleaning_booking_session_id')
                ->nullable()
                ->after('booking_type')
                ->constrained('cleaning_booking_sessions')
                ->nullOnDelete();
            $table->dropUnique('wcr_booking_worker_customer_rating_unique');
            $table->unique(
                [
                    'booking_id',
                    'booking_type',
                    'cleaning_booking_session_id',
                    'worker_id',
                    'customer_id',
                    'rating_type',
                ],
                'wcr_booking_session_worker_rating_unique',
            );
        });

        Schema::table('disputes', function (Blueprint $table): void {
            $table->foreignId('cleaning_booking_session_id')
                ->nullable()
                ->after('booking_type')
                ->constrained('cleaning_booking_sessions')
                ->nullOnDelete();
            $table->index(
                ['cleaning_booking_session_id', 'status'],
                'disputes_cleaning_session_status_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('disputes', function (Blueprint $table): void {
            $table->dropIndex('disputes_cleaning_session_status_idx');
            $table->dropConstrainedForeignId('cleaning_booking_session_id');
        });

        Schema::table('worker_customer_ratings', function (Blueprint $table): void {
            $table->dropUnique('wcr_booking_session_worker_rating_unique');
            $table->dropConstrainedForeignId('cleaning_booking_session_id');
            $table->unique(
                ['booking_id', 'booking_type', 'worker_id', 'customer_id', 'rating_type'],
                'wcr_booking_worker_customer_rating_unique',
            );
        });

        Schema::table('cleaning_booking_sessions', function (Blueprint $table): void {
            $table->dropIndex('cleaning_session_payment_status_idx');
            $table->dropColumn(['payment_status', 'payment_settled_at']);
        });
    }
};
