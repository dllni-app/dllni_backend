<?php

declare(strict_types=1);

use App\Enums\MasterProductUnit;
use Modules\Supermarket\Services\OpenFoodFactsMasterProductMapper;

it('accepts en:syria in countries tags', function (): void {
    $mapper = app(OpenFoodFactsMasterProductMapper::class);

    expect($mapper->countryMatches(['en:france', 'en:syria'], 'en:syria'))->toBeTrue();
    expect($mapper->countryMatches(['en:france', 'en:spain'], 'en:syria'))->toBeFalse();
});

it('maps units from openfoodfacts quantity fields', function (array $record, MasterProductUnit $expected): void {
    $mapper = app(OpenFoodFactsMasterProductMapper::class);

    expect($mapper->mapUnit($record))->toBe($expected);
})->with([
    'grams' => [
        ['product_quantity_unit' => 'g', 'quantity' => '500 g'],
        MasterProductUnit::Gram,
    ],
    'kilograms' => [
        ['product_quantity_unit' => 'kg', 'quantity' => '1 kg'],
        MasterProductUnit::Kilogram,
    ],
    'milliliters' => [
        ['product_quantity_unit' => 'ml', 'quantity' => '250 ml'],
        MasterProductUnit::Milliliter,
    ],
    'liters' => [
        ['product_quantity_unit' => 'l', 'quantity' => '1 L'],
        MasterProductUnit::Liter,
    ],
    'pieces' => [
        ['product_quantity_unit' => 'pcs', 'quantity' => '6 pcs'],
        MasterProductUnit::Piece,
    ],
    'packs' => [
        ['product_quantity_unit' => null, 'quantity' => '12 pack'],
        MasterProductUnit::Pack,
    ],
    'unknown defaults to pack' => [
        ['product_quantity_unit' => 'unknown', 'quantity' => 'mystery'],
        MasterProductUnit::Pack,
    ],
]);

it('prefers arabic product name then default product name during mapping', function (): void {
    $mapper = app(OpenFoodFactsMasterProductMapper::class);

    $arabicFirst = $mapper->map([
        'code' => '1234567890123',
        'product_name_ar' => 'حليب عربي',
        'product_name' => 'Milk Default',
        'countries_tags' => ['en:syria'],
    ]);

    $defaultFallback = $mapper->map([
        'code' => '1234567890124',
        'product_name' => 'Milk Default',
        'countries_tags' => ['en:syria'],
    ]);

    expect($arabicFirst)->not->toBeNull();
    expect($defaultFallback)->not->toBeNull();
    expect($arabicFirst['name'])->toBe('حليب عربي');
    expect($defaultFallback['name'])->toBe('Milk Default');
});

