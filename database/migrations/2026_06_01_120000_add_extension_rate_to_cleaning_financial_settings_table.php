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
            if (! Schema::hasColumn('cleaning_financial_settings', 'extension_rate_per_30_minutes')) {
                $table->decimal('extension_rate_per_30_minutes', 10, 2)
                    ->default(0)
                    ->after('time_warning_minutes_before_end');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_financial_settings', 'extension_rate_per_30_minutes')) {
                $table->dropColumn('extension_rate_per_30_minutes');
            }
        });
    }
};
