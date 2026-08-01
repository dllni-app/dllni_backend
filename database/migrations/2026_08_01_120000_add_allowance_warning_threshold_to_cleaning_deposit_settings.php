<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cleaning_deposit_settings', function (Blueprint $table): void {
            if (! Schema::hasColumn('cleaning_deposit_settings', 'allowance_warning_threshold_percent')) {
                $table->decimal('allowance_warning_threshold_percent', 5, 2)
                    ->default(10)
                    ->after('restriction_threshold_percent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_deposit_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_deposit_settings', 'allowance_warning_threshold_percent')) {
                $table->dropColumn('allowance_warning_threshold_percent');
            }
        });
    }
};
