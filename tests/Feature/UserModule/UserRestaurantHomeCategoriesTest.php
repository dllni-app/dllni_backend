<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Resturants\Models\CuisineType;
use Modules\Resturants\Models\Restaurant;

it('returns cuisine categories that have at least one active restaurant', function (): void {
    $italian = CuisineType::create([
        'name' => 'Italian',
        'slug' => 'italian',
    ]);
    $unused = CuisineType::create([
        'name' => 'Unused',
        'slug' => 'unused',
    ]);

    $activeRestaurant = Restaurant::factory()->create(['is_active' => true]);
    $activeRestaurant->cuisineTypes()->attach($italian->id);

    $response = $this->getJson('/api/v1/user/restaurants/home/categories');

    $response->assertOk();
    $slugs = collect($response->json('categories'))->pluck('slug')->all();
    expect($slugs)->toContain('italian');
    expect($slugs)->not->toContain('unused');
});

it('includes category imageUrl from active restaurant primary image when available', function (): void {
    Storage::fake('public');

    $cuisine = CuisineType::create([
        'name' => 'Italian',
        'slug' => 'italian',
    ]);

    $activeRestaurant = Restaurant::factory()->create(['is_active' => true]);
    $activeRestaurant->addMedia(UploadedFile::fake()->image('restaurant.jpg'))
        ->toMediaCollection('primary-image');
    $activeRestaurant->cuisineTypes()->attach($cuisine->id);

    $response = $this->getJson('/api/v1/user/restaurants/home/categories');

    $response->assertOk();
    $category = collect($response->json('categories'))->firstWhere('slug', 'italian');

    expect($category)->not->toBeNull();
    expect($category)->toHaveKey('image');
    expect($category['image'])->toBeString()->not->toBe('');
});

it('excludes categories that are only linked to inactive restaurants', function (): void {
    $cuisine = CuisineType::create([
        'name' => 'Syrian',
        'slug' => 'syrian',
    ]);

    $inactiveRestaurant = Restaurant::factory()->inactive()->create();
    $inactiveRestaurant->cuisineTypes()->attach($cuisine->id);

    $response = $this->getJson('/api/v1/user/restaurants/home/categories');

    $response->assertOk();
    expect($response->json('categories'))->toBeArray()->toBeEmpty();
});
