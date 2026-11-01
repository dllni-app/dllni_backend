<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cleaning_financial_settings')
            && ! Schema::hasColumn('cleaning_financial_settings', 'supervisor_compensation_hourly_rate')) {
            Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
                $table->decimal('supervisor_compensation_hourly_rate', 12, 2)
                    ->default(0)
                    ->after('supervisor_hourly_rate');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('cleaning_financial_settings')
            && Schema::hasColumn('cleaning_financial_settings', 'supervisor_compensation_hourly_rate')) {
            Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
                $table->dropColumn('supervisor_compensation_hourly_rate');
            });
        }
    }
};
