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
            'data' => ['حليب', 'رز'],
        ]);

    $this->getJson('/api/v1/user/popular-searches?section=restaurant')
        ->assertOk()
        ->assertJson([
            'section' => 'restaurant',
            'data' => ['برغر'],
        ]);
});

it('rejects unsupported popular search sections', function (): void {
    $this->getJson('/api/v1/user/popular-searches?section=cleaning')
        ->assertUnprocessable();
});
