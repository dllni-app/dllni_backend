<?php

declare(strict_types=1);

use Modules\Resturants\Data\ProductData;
use Modules\User\Services\SmartSearchUnitNormalizer;

it('normalizes Arabic and English measurement aliases', function (): void {
    $normalizer = new SmartSearchUnitNormalizer();

    expect($normalizer->normalize('كيلو غرام'))->toBe('kg')
        ->and($normalizer->normalize('كغ'))->toBe('kg')
        ->and($normalizer->normalize('غرام'))->toBe('g')
        ->and($normalizer->normalize('لتر'))->toBe('l')
        ->and($normalizer->normalize('مل'))->toBe('ml');
});

it('calculates package counts and unit conversions deterministically', function (): void {
    $normalizer = new SmartSearchUnitNormalizer();

    expect($normalizer->packagesRequired(10, 'kg', 5, 'kg'))->toMatchArray([
        'packages' => 2,
        'fulfilledQuantity' => 10.0,
        'fulfilledUnit' => 'kg',
        'overage' => 0.0,
        'exact' => true,
    ]);

    expect($normalizer->packagesRequired(1, 'kg', 250, 'g'))->toMatchArray([
        'packages' => 4,
        'fulfilledQuantity' => 1000.0,
        'fulfilledUnit' => 'g',
        'overage' => 0.0,
        'exact' => true,
    ]);
});

it('keeps restaurant product data backward compatible when smart metadata is omitted', function (): void {
    $data = ProductData::from([
        'restaurantId' => 1,
        'categoryId' => 2,
        'name' => 'Legacy product',
        'description' => null,
        'price' => 10,
        'discountedPrice' => null,
        'isAvailable' => true,
        'stockQuantity' => 5,
        'lowStockThreshold' => 1,
        'preparationTime' => 10,
        'isFeatured' => false,
    ]);

    expect($data->itemType)->toBeNull()
        ->and($data->searchTags)->toBeNull();
});

it('resolves common preparation contexts into a compact deterministic basket', function (): void {
    $resolver = new Modules\User\Services\SmartSearchContextResolver();

    $breakfast = $resolver->resolve('أغراض للفطور');

    expect($breakfast)->not->toBeNull()
        ->and($breakfast['key'])->toBe('breakfast')
        ->and($breakfast['items'])->toHaveCount(6)
        ->and($breakfast['items'][0]['query'])->toBe('خبز');
});
