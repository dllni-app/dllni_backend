<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Modules\Resturants\Jobs\SyncRestaurantMealIndex;
use Modules\Resturants\Models\Product;

final class ReindexRestaurantMeals extends Command
{
    protected $signature = 'restaurant-search:reindex {--product= : Restrict to one product ID}';
    protected $description = 'Queue a refresh of restaurant meal search documents';

    public function handle(): int
    {
        if (!config('services.dallelni_search.meal_index_enabled')) {
            $this->error('Enable DALLELNI_MEAL_INDEX_ENABLED for the intended environment first.');
            return self::FAILURE;
        }
        $count = 0;
        $query = Product::query()->select('id');
        if ($id = $this->option('product')) $query->whereKey($id);
        $query->chunkById(200, function ($products) use (&$count): void {
            foreach ($products as $product) {
                SyncRestaurantMealIndex::dispatch((int) $product->id);
                $count++;
            }
        });
        $this->info("Queued {$count} product index refreshes.");
        return self::SUCCESS;
    }
}
