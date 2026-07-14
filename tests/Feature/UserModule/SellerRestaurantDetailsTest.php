<?php

declare(strict_types=1);

use Database\Seeders\CleaningWorkerAndSellerSeeder;
use Modules\Resturants\Models\Restaurant;

beforeEach(function (): void {
    $this->seed(CleaningWorkerAndSellerSeeder::class);
});

it('returns seller restaurant details with categories and products', function (): void {
    $restaurant = Restaurant::where('name', 'Seller Restaurant')->first();

    expect($restaurant)->not->toBeNull();
    expect($restaurant->id)->toBe(1);

    // Act
    $response = $this->getJson("/api/v1/user/restaurants/{$restaurant->id}");

    // Assert - response structure
    $response->assertOk()->assertJsonStructure([
        'restaurant' => [
            'id',
            'name',
            'cuisineTypes',
            'operatingHours' => [
                '*' => [
                    'id',
                    'dayOfWeek',
                    'openTime',
                    'closeTime',
                    'isClosed',
                    'day_of_week',
                    'open_time',
                    'close_time',
                    'is_closed',
                ],
            ],
            'imageUrl',
            'images',
        ],
        'offers',
        'popularProducts',
        'categories',
        'ratingSummary' => ['average', 'total', 'counts'],
        'reviews',
    ]);

    // Assert - restaurant has data
    $data = $response->json();

    expect($data['restaurant']['name'])->toBe('Seller Restaurant');
    expect($data['restaurant']['cuisineTypes'])->toBeArray()->not->toBeEmpty();
    expect($data['restaurant']['operatingHours'])->toBeArray()->not->toBeEmpty();

    $firstOperatingHour = $data['restaurant']['operatingHours'][0];
    expect($firstOperatingHour['dayOfWeek'])->toBe($firstOperatingHour['day_of_week']);
    expect($firstOperatingHour['openTime'])->toBe($firstOperatingHour['open_time']);
    expect($firstOperatingHour['closeTime'])->toBe($firstOperatingHour['close_time']);
    expect($firstOperatingHour['isClosed'])->toBe($firstOperatingHour['is_closed']);

    // Assert - has categories and products
    expect($data['categories'])->toBeArray()->not->toBeEmpty();
    expect(count($data['categories']))->toBe(3); // Main Courses, Appetizers, Desserts

    // Check if products are in categories
    foreach ($data['categories'] as $category) {
        expect($category['products'])->toBeArray()->not->toBeEmpty();
    }

    // Check total products count should be 9 (3 per category, 3 categories)
    $totalProducts = collect($data['categories'])
        ->pluck('products')
        ->flatten(1)
        ->count();
    expect($totalProducts)->toBe(9);
});
