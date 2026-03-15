<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Database\Factories\SmStoreStaffFactory;
use Illuminate\Database\Seeder;
use Modules\Supermarket\Models\SmStore;

final class SmStoreStaffSeeder extends Seeder
{
    public function run(): void
    {
        $stores = SmStore::query()->get();

        foreach ($stores as $store) {
            if ($store->staff()->exists()) {
                continue;
            }

            SmStoreStaffFactory::new()
                ->count(2)
                ->create([
                    'store_id' => $store->id,
                ]);
        }
    }
}
