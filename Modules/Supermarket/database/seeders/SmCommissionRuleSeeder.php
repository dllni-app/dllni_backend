<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Supermarket\Enums\SmCommissionType;
use Modules\Supermarket\Models\SmCommissionRule;
use Modules\Supermarket\Models\SmStore;

final class SmCommissionRuleSeeder extends Seeder
{
    public function run(): void
    {
        SmStore::each(function (SmStore $store): void {
            SmCommissionRule::firstOrCreate(
                [
                    'store_id' => $store->id,
                    'is_default' => true,
                ],
                [
                    'commission_type' => SmCommissionType::Percentage->value,
                    'value' => 5.00,
                    'min_order_amount' => null,
                    'max_commission_amount' => 50.00,
                    'starts_at' => now(),
                    'ends_at' => null,
                    'is_active' => true,
                ]
            );
        });
    }
}
