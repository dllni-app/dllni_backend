<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Supermarket\Models\SmStore;

/**
 * Refresh only the known demo catalog. Do not reseed orders, coupons,
 * commission rules, or normalize prices in unrelated modules.
 */
final class SmDemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        SmDemoCatalog::load();

        $this->call([
            SmStoreSeeder::class,
            SmCategorySeeder::class,
            SmProductSeeder::class,
        ]);

        $storeIds = SmStore::query()
            ->whereIn('slug', ['supermarket-al-atrash', 'supermarket-al-sultan', 'supermarket-al-noor'])
            ->orWhere('slug', 'like', 'seller-supermarket-%')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->callWith(SmProductMasterLinkSeeder::class, ['storeIds' => $storeIds]);
        $this->call(SmOfferSeeder::class);
    }
}
