<?php

declare(strict_types=1);

namespace Modules\Resturants\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Modules\Resturants\Services\RestaurantMealIndexDocument;

final class SyncRestaurantMealIndex implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(public int $productId)
    {
        // Keep the existing default queue unless the environment opts into
        // an isolated worker for semantic catalog refreshes.
        if ($queue = config('services.dallelni_search.meal_index_queue')) {
            $this->onQueue($queue);
        }
    }

    public function handle(): void
    {
        if (!config('services.dallelni_search.meal_index_enabled')) return;
        $base = rtrim((string) config('services.dallelni_search.restaurant_products_base_url'), '/');
        $token = (string) config('services.dallelni_search.auth_token');
        if ($base === '' || $token === '') throw new \RuntimeException('Meal index configuration is incomplete.');
        $client = Http::timeout((int) config('services.dallelni_search.meal_index_timeout', 10))->acceptJson()->withHeaders(['auth-token' => $token]);
        $product = RestaurantMealIndexDocument::query()->find($this->productId);
        if ($product === null) {
            $response = $client->delete($base.'/meals/'.$this->productId);
            if ($response->status() !== 404) $response->throw();
            return;
        }
        $client->post($base.'/meals/upsert', RestaurantMealIndexDocument::fields($product))->throw();
    }
}
