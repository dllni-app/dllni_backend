<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_financial_settings', 'cancellation_user_free_until_hours')) {
                $table->unsignedInteger('cancellation_user_free_until_hours')->default(24)->after('extension_ranges');
            }
            if (! Schema::hasColumn('cleaning_financial_settings', 'cancellation_user_within_24h_percentage')) {
                $table->decimal('cancellation_user_within_24h_percentage', 5, 2)->default(25)->after('cancellation_user_free_until_hours');
            }
            if (! Schema::hasColumn('cleaning_financial_settings', 'cancellation_user_within_12h_percentage')) {
                $table->decimal('cancellation_user_within_12h_percentage', 5, 2)->default(50)->after('cancellation_user_within_24h_percentage');
            }
            if (! Schema::hasColumn('cleaning_financial_settings', 'cancellation_worker_fee_percentage')) {
                $table->decimal('cancellation_worker_fee_percentage', 5, 2)->default(25)->after('cancellation_user_within_12h_percentage');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            foreach ([
                'cancellation_user_free_until_hours',
                'cancellation_user_within_24h_percentage',
                'cancellation_user_within_12h_percentage',
                'cancellation_worker_fee_percentage',
            ] as $column) {
                if (Schema::hasColumn('cleaning_financial_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
