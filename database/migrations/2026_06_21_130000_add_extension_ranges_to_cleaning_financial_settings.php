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
            if (! Schema::hasColumn('cleaning_financial_settings', 'extension_ranges')) {
                $table->json('extension_ranges')->nullable()->after('extension_rate_per_30_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_financial_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_financial_settings', 'extension_ranges')) {
                $table->dropColumn('extension_ranges');
            }
        });
    }
};
