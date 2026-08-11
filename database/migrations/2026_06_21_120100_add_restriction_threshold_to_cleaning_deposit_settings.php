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
            if (! Schema::hasColumn('cleaning_deposit_settings', 'restriction_threshold_percent')) {
                $table->decimal('restriction_threshold_percent', 5, 2)->default(80)->after('default_max_negative_balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cleaning_deposit_settings', function (Blueprint $table): void {
            if (Schema::hasColumn('cleaning_deposit_settings', 'restriction_threshold_percent')) {
                $table->dropColumn('restriction_threshold_percent');
            }
        });
    }
};
