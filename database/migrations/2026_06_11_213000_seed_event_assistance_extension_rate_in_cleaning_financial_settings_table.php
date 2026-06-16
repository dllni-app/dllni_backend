<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_EXTENSION_RATE_PER_30_MINUTES = 4500.00;

    public function up(): void
    {
        if (
            ! Schema::hasTable('cleaning_financial_settings')
            || ! Schema::hasColumn('cleaning_financial_settings', 'extension_rate_per_30_minutes')
        ) {
            return;
        }

        $now = now();

        $updated = DB::table('cleaning_financial_settings')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('extension_rate_per_30_minutes')
                    ->orWhere('extension_rate_per_30_minutes', '<=', 0);
            })
            ->update([
                'extension_rate_per_30_minutes' => self::DEFAULT_EXTENSION_RATE_PER_30_MINUTES,
                'updated_at' => $now,
            ]);

        if ($updated > 0 || DB::table('cleaning_financial_settings')->exists()) {
            return;
        }

        DB::table('cleaning_financial_settings')->insert([
            'id' => 1,
            'default_commission_rate' => 0,
            'vat_rate' => 0,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0,
            'extension_rate_per_30_minutes' => self::DEFAULT_EXTENSION_RATE_PER_30_MINUTES,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('cleaning_financial_settings')
            || ! Schema::hasColumn('cleaning_financial_settings', 'extension_rate_per_30_minutes')
        ) {
            return;
        }

        DB::table('cleaning_financial_settings')
            ->where('extension_rate_per_30_minutes', self::DEFAULT_EXTENSION_RATE_PER_30_MINUTES)
            ->update([
                'extension_rate_per_30_minutes' => 0,
                'updated_at' => now(),
            ]);
    }
};
