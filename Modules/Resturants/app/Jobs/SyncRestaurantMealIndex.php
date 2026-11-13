<?php

declare(strict_types=1);

namespace Modules\Resturants\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Http;
use Modules\Resturants\Models\Product;

final class SyncRestaurantMealIndex implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];

    public function __construct(public int $productId) {}

    public function handle(): void
    {
        if (!config('services.dallelni_search.meal_index_enabled')) return;
        $base = rtrim((string) config('services.dallelni_search.restaurant_products_base_url'), '/');
        $token = (string) config('services.dallelni_search.auth_token');
        if ($base === '' || $token === '') throw new \RuntimeException('Meal index configuration is incomplete.');
        $client = Http::timeout((int) config('services.dallelni_search.timeout', 10))->acceptJson()->withHeaders(['auth-token' => $token]);
        $product = Product::query()->with(['restaurant', 'category', 'foodCategory'])->find($this->productId);
        if ($product === null || !$product->isIndependentlyOrderable() || !$product->restaurant?->is_active) {
            $response = $client->delete($base.'/meals/'.$this->productId);
            if ($response->status() !== 404) $response->throw();
            return;
        }
        $restaurant = $product->restaurant;
        $tags = array_values(array_unique(array_filter(array_merge($product->search_tags ?? [], [$product->foodCategory?->name_ar, $product->foodCategory?->slug, $product->product_kind, $product->servings ? 'servings:'.$product->servings : null]))));
        $client->post($base.'/meals/upsert', [
            'product_id' => $product->id,
            'name' => $product->name,
            'description' => (string) $product->description,
            'item_type' => $product->item_type,
            'search_tags' => $tags,
            'price' => (float) $product->price,
            'discounted_price' => $product->discounted_price === null ? null : (float) $product->discounted_price,
            'preparation_time' => $product->preparation_time,
            'is_available' => $product->isAvailableNow(),
            'is_featured' => (bool) $product->is_featured,
            'restaurant_id' => $restaurant->id,
            'restaurant_name' => $restaurant->name,
            'restaurant_preparation_min' => $restaurant->estimated_preparation_time_min,
            'restaurant_preparation_max' => $restaurant->estimated_preparation_time_max,
            'restaurant_rating' => (float) $restaurant->average_rating,
            'restaurant_reputation' => (float) $restaurant->reputation_score,
            'category_id' => $product->category_id,
            'category_name' => $product->category?->name,
        ])->throw();
    }
}
