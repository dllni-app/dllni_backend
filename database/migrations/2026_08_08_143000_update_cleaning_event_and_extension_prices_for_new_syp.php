<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->applyPricing(
            eventRatePerThirtyMinutes: 200.00,
            extensionRanges: [
                ['start' => 0, 'end' => 15, 'price' => 10.00],
                ['start' => 16, 'end' => 30, 'price' => 25.00],
                ['start' => 31, 'end' => 45, 'price' => 50.00],
                ['start' => 46, 'end' => 60, 'price' => 100.00],
                ['start' => 61, 'end' => 75, 'price' => 200.00],
                ['start' => 76, 'end' => 90, 'price' => 500.00],
            ],
        );
    }

    public function down(): void
    {
        $this->applyPricing(
            eventRatePerThirtyMinutes: 4500.00,
            extensionRanges: [
                ['start' => 0, 'end' => 15, 'price' => 2250.00],
                ['start' => 16, 'end' => 30, 'price' => 4500.00],
                ['start' => 31, 'end' => 45, 'price' => 6750.00],
                ['start' => 46, 'end' => 60, 'price' => 9000.00],
                ['start' => 61, 'end' => 75, 'price' => 11250.00],
                ['start' => 76, 'end' => 90, 'price' => 13500.00],
            ],
        );
    }

    /** @param array<int, array{start:int, end:int, price:float}> $extensionRanges */
    private function applyPricing(float $eventRatePerThirtyMinutes, array $extensionRanges): void
    {
        if (! Schema::hasTable('cleaning_financial_settings')) {
            return;
        }

        $updates = [];

        if (Schema::hasColumn('cleaning_financial_settings', 'extension_rate_per_30_minutes')) {
            $updates['extension_rate_per_30_minutes'] = $eventRatePerThirtyMinutes;
        }

        if (Schema::hasColumn('cleaning_financial_settings', 'extension_ranges')) {
            $updates['extension_ranges'] = json_encode($extensionRanges, JSON_THROW_ON_ERROR);
        }

        if (Schema::hasColumn('cleaning_financial_settings', 'updated_at')) {
            $updates['updated_at'] = now();
        }

        if ($updates !== []) {
            DB::table('cleaning_financial_settings')->update($updates);
        }
    }
};
