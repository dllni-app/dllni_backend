<?php

declare(strict_types=1);

namespace Modules\Cleaning\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CleaningDepositSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $table = 'cleaning_deposit_settings';

        if (! Schema::hasTable($table) || DB::table($table)->exists()) {
            return;
        }

        $now = now();
        $defaults = [
            'minimum_deposit_amount' => 0,
            // FinancialSettings currently treats 100% as the operational default.
            'restriction_threshold_percent' => 100,
            'allowance_warning_threshold_percent' => 10,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
            // Retained compatibility fields from older migrations.
            'default_max_negative_balance' => 0,
            'is_enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $payload = [];
        foreach ($defaults as $column => $value) {
            if (Schema::hasColumn($table, $column)) {
                $payload[$column] = $value;
            }
        }

        if ($payload !== []) {
            DB::table($table)->insert($payload);
        }
    }
}
