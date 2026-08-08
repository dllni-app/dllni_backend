<?php

declare(strict_types=1);

use App\Support\SyrianPoundPrice;

it('keeps the supported new Syrian pound price points unchanged', function (float $price): void {
    expect(SyrianPoundPrice::normalize($price))->toBe($price);
})->with([
    10.0,
    25.0,
    50.0,
    100.0,
    200.0,
    500.0,
]);

it('converts legacy thousand-based amounts and rounds them up to the supported price points', function (
    float $legacyPrice,
    float $expected
): void {
    expect(SyrianPoundPrice::normalize($legacyPrice))->toBe($expected);
})->with([
    [4000.0, 10.0],
    [9500.0, 10.0],
    [12000.0, 25.0],
    [18000.0, 25.0],
    [34000.0, 50.0],
    [45000.0, 50.0],
    [110000.0, 200.0],
    [280000.0, 500.0],
]);

it('rounds existing small catalog values up to the supported price points', function (
    float $price,
    float $expected
): void {
    expect(SyrianPoundPrice::normalize($price))->toBe($expected);
})->with([
    [2.5, 10.0],
    [14.99, 25.0],
    [35.0, 50.0],
    [85.0, 100.0],
    [150.0, 200.0],
    [280.0, 500.0],
]);

it('keeps discounted prices below the normalized regular price when a lower tier exists', function (): void {
    expect(SyrianPoundPrice::normalizeDiscount(31500, 34000))->toBe(25.0)
        ->and(SyrianPoundPrice::normalizeDiscount(30, 35))->toBe(25.0)
        ->and(SyrianPoundPrice::normalizeDiscount(null, 35))->toBeNull();
});
