<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Resturants\Models\FoodDiscoveryCategory;
use Modules\Resturants\Models\Product;
use Tests\TestCase;

final class RestaurantFoodCategorySearchContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('local', app()->environment());
        self::assertSame('mysql', config('database.default'));
        self::assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost']);
        self::assertSame('alnadha_worker_test', DB::connection()->getDatabaseName());
    }

    public function test_category_only_search_is_exact_and_paginated(): void
    {
        $category = FoodDiscoveryCategory::query()->where('slug', 'shawarma')->firstOrFail();
        $url = '/api/v2/user/restaurants/search?scope=dishes&foodCategoryId='.$category->id;
        $first = $this->getJson($url.'&perPage=5')->assertOk();
        $second = $this->getJson($url.'&perPage=5&page=2')->assertOk();
        $rows = [...$first->json('data'), ...$second->json('data')];
        self::assertCount(10, $rows);
        self::assertCount(10, array_unique(array_column($rows, 'id')));
        foreach ($rows as $row) {
            self::assertSame((int) $category->id, $row['foodCategory']['id']);
            self::assertNotEmpty($row['restaurant']['name']);
            self::assertNotEmpty($row['imageUrl']);
        }

        $unreviewed = Product::query()->findOrFail($rows[0]['id']);
        $unreviewed->forceFill(['classification_status' => 'unreviewed'])->save();
        $ids = array_column($this->getJson($url.'&perPage=50')->assertOk()->json('data'), 'id');
        self::assertNotContains($unreviewed->id, $ids);
        $discovery = $this->getJson('/api/v2/user/restaurants/search?scope=dishes')->assertOk()->json('data');
        self::assertNotEmpty($discovery);
        foreach ($discovery as $item) self::assertTrue($item['isIndependentlyOrderable']);
    }

    public function test_category_price_location_and_distance_filters(): void
    {
        $category = FoodDiscoveryCategory::query()->where('slug', 'shawarma')->firstOrFail();
        $url = '/api/v2/user/restaurants/search?scope=dishes&perPage=50&foodCategoryId='.$category->id;
        $rows = $this->getJson($url.'&sort=price_asc&minPrice=25&maxPrice=150')->assertOk()->json('data');
        self::assertNotEmpty($rows);
        $prices = array_map(fn ($r) => (float) $r['price'], $rows);
        $sorted = $prices;
        sort($sorted, SORT_NUMERIC);
        self::assertSame($sorted, $prices);
        foreach ($prices as $price) {
            self::assertGreaterThanOrEqual(25, $price);
            self::assertLessThanOrEqual(150, $price);
        }
        $desc = $this->getJson($url.'&sort=price_desc&maxPrice=150')->assertOk()->json('data');
        $descPrices = array_map(fn ($r) => (float) $r['price'], $desc);
        $sortedDesc = $descPrices;
        rsort($sortedDesc, SORT_NUMERIC);
        self::assertSame($sortedDesc, $descPrices);

        $area = $this->getJson($url.'&area='.rawurlencode('الحمدانية'))->assertOk()->json('data');
        self::assertNotEmpty($area);
        foreach ($area as $row) {
            self::assertSame('الحمدانية', Product::query()->findOrFail($row['id'])->restaurant->district);
        }

        $nearest = $this->getJson($url.'&sort=nearest&latitude=36.1812&longitude=37.1117')->assertOk()->json('data');
        self::assertNotEmpty($nearest);
        $distances = array_map(fn ($r) => (float) $r['distanceKm'], $nearest);
        $sortedDistances = $distances;
        sort($sortedDistances, SORT_NUMERIC);
        self::assertSame($sortedDistances, $distances);
        $this->getJson($url.'&minPrice=150&maxPrice=25')->assertUnprocessable()->assertJsonValidationErrors('maxPrice');
    }

    public function test_combined_category_search_returns_only_matching_restaurants_and_products(): void
    {
        $category = FoodDiscoveryCategory::query()->where('slug', 'shawarma')->firstOrFail();
        $url = '/api/v2/user/restaurants/search?scope=all&foodCategoryId='.$category->id.'&perPage=50';
        $result = $this->getJson($url)->assertOk()->json('data');
        $dishes = $result['dishes']['items'];
        $restaurants = $result['restaurants']['items'];
        self::assertNotEmpty($dishes);
        self::assertNotEmpty($restaurants);
        $dishRestaurantIds = array_values(array_unique(array_column($dishes, 'restaurantId')));
        $restaurantIds = array_column($restaurants, 'id');
        sort($dishRestaurantIds);
        sort($restaurantIds);
        self::assertSame($dishRestaurantIds, $restaurantIds);
        foreach ($dishes as $dish) {
            self::assertSame((int) $category->id, $dish['foodCategory']['id']);
        }

        $filtered = $this->getJson($url.'&sort=price_asc&maxPrice=75')->assertOk()->json('data');
        $matchingIds = array_values(array_unique(array_column($filtered['dishes']['items'], 'restaurantId')));
        $filteredIds = array_column($filtered['restaurants']['items'], 'id');
        sort($matchingIds);
        sort($filteredIds);
        self::assertNotEmpty($matchingIds);
        self::assertSame($matchingIds, $filteredIds);
        $text = $this->getJson($url.'&q='.rawurlencode('شاورما دجاج'))->assertOk()->json('data');
        self::assertNotEmpty($text['dishes']['items']);
        self::assertNotEmpty($text['restaurants']['items']);
    }
}
