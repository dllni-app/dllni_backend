<?php

declare(strict_types=1);

namespace Modules\Resturants\Observers;

use Modules\Resturants\Jobs\SyncRestaurantMealIndex;
use Modules\Resturants\Models\Product;

final class ProductMealIndexObserver
{
    public function saved(Product $product): void
    {
        $this->dispatch($product);
    }

    public function deleted(Product $product): void
    {
        $this->dispatch($product);
    }

    private function dispatch(Product $product): void
    {
        if (config('services.dallelni_search.meal_index_enabled')) {
            SyncRestaurantMealIndex::dispatch((int) $product->id)->afterCommit();
        }
    }
}
