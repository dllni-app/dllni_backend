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
            $table->string('booking_kind', 24)->default('standard')->after('status');
            $table->decimal('open_time_hourly_rate', 12, 2)->nullable()->after('base_price');
            $table->unsignedSmallInteger('open_time_minimum_minutes')->nullable()->after('open_time_hourly_rate');
            $table->unsignedSmallInteger('open_time_rounding_minutes')->nullable()->after('open_time_minimum_minutes');
            $table->unsignedInteger('open_time_actual_minutes')->nullable()->after('open_time_rounding_minutes');
            $table->unsignedInteger('open_time_billable_minutes')->nullable()->after('open_time_actual_minutes');
            $table->decimal('open_time_final_amount', 12, 2)->nullable()->after('open_time_billable_minutes');
            $table->timestamp('open_time_finalized_at')->nullable()->after('open_time_final_amount');
            $table->index(['booking_kind', 'status'], 'clean_book_kind_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_bookings', function (Blueprint $table): void {
            $table->dropIndex('clean_book_kind_status_idx');
            $table->dropColumn([
                'booking_kind',
                'open_time_hourly_rate',
                'open_time_minimum_minutes',
                'open_time_rounding_minutes',
                'open_time_actual_minutes',
                'open_time_billable_minutes',
                'open_time_final_amount',
                'open_time_finalized_at',
            ]);
        });
    }
};
