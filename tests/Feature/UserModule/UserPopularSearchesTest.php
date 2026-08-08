<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('returns popular searches scoped by requested section', function (): void {
    $now = now();

    DB::table('user_search_terms')->insert([
        [
            'section' => 'supermarket',
            'query' => 'حليب',
            'normalized_query' => 'حليب',
            'searches_count' => 12,
            'last_searched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'section' => 'supermarket',
            'query' => 'رز',
            'normalized_query' => 'رز',
            'searches_count' => 8,
            'last_searched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'section' => 'restaurant',
            'query' => 'برغر',
            'normalized_query' => 'برغر',
            'searches_count' => 20,
            'last_searched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $this->getJson('/api/v1/user/popular-searches?section=supermarket&limit=2')
        ->assertOk()
        ->assertJson([
            'section' => 'supermarket',
            'filter' => null,
            'data' => ['حليب', 'رز'],
        ]);

    $this->getJson('/api/v1/user/popular-searches?section=restaurant')
        ->assertOk()
        ->assertJson([
            'section' => 'restaurant',
            'filter' => null,
            'data' => ['برغر'],
        ]);
});

it('filters popular searches by products or merchants', function (): void {
    $now = now();

    DB::table('user_search_terms')->insert([
        [
            'section' => 'supermarket',
            'query' => 'حليب',
            'normalized_query' => 'حليب',
            'searches_count' => 21,
            'product_searches_count' => 20,
            'merchant_searches_count' => 1,
            'last_searched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'section' => 'supermarket',
            'query' => 'سوق الخير',
            'normalized_query' => 'سوق الخير',
            'searches_count' => 15,
            'product_searches_count' => 0,
            'merchant_searches_count' => 15,
            'last_searched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'section' => 'supermarket',
            'query' => 'رز',
            'normalized_query' => 'رز',
            'searches_count' => 9,
            'product_searches_count' => 9,
            'merchant_searches_count' => 0,
            'last_searched_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $this->getJson('/api/v1/user/popular-searches?section=supermarket&filter=products')
        ->assertOk()
        ->assertJson([
            'section' => 'supermarket',
            'filter' => 'products',
            'data' => ['حليب', 'رز'],
        ]);

    $this->getJson('/api/v1/user/popular-searches?section=supermarket&filter=merchants')
        ->assertOk()
        ->assertJson([
            'section' => 'supermarket',
            'filter' => 'merchants',
            'data' => ['سوق الخير', 'حليب'],
        ]);
});

it('rejects unsupported popular search sections', function (): void {
    $this->getJson('/api/v1/user/popular-searches?section=cleaning')
        ->assertUnprocessable();
});

it('rejects unsupported popular search filters', function (): void {
    $this->getJson('/api/v1/user/popular-searches?section=supermarket&filter=stores')
        ->assertUnprocessable();
});
