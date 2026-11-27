<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3LiveExtensionPricing;

test('live extension advertises only whole half-hour steps below the eight-hour visit limit', function (): void {
    expect(CleaningV3LiveExtensionPricing::maxAdditionalMinutes(420))->toBe(60)
        ->and(CleaningV3LiveExtensionPricing::maxAdditionalMinutes(425))->toBe(30)
        ->and(CleaningV3LiveExtensionPricing::maxAdditionalMinutes(450))->toBe(30)
        ->and(CleaningV3LiveExtensionPricing::maxAdditionalMinutes(451))->toBe(0)
        ->and(CleaningV3LiveExtensionPricing::maxAdditionalMinutes(480))->toBe(0)
        ->and(CleaningV3LiveExtensionPricing::maxAdditionalMinutes(481))->toBe(0);
});

test('the visit limit cannot be bypassed by oversized invalid or fractional-step extensions', function (): void {
    expect(CleaningV3LiveExtensionPricing::allowsDuration(420, 60))->toBeTrue()
        ->and(CleaningV3LiveExtensionPricing::allowsDuration(420, 90))->toBeFalse()
        ->and(CleaningV3LiveExtensionPricing::allowsDuration(425, 30))->toBeTrue()
        ->and(CleaningV3LiveExtensionPricing::allowsDuration(425, 55))->toBeFalse()
        ->and(CleaningV3LiveExtensionPricing::allowsDuration(450, 30))->toBeTrue()
        ->and(CleaningV3LiveExtensionPricing::allowsDuration(451, 30))->toBeFalse()
        ->and(CleaningV3LiveExtensionPricing::allowsDuration(480, 30))->toBeFalse()
        ->and(CleaningV3LiveExtensionPricing::allowsDuration(0, 30))->toBeFalse()
        ->and(CleaningV3LiveExtensionPricing::allowsDuration(-1, 30))->toBeFalse()
        ->and(CleaningV3LiveExtensionPricing::allowsDuration(120, -30))->toBeFalse()
        ->and(CleaningV3LiveExtensionPricing::allowsDuration(120, PHP_INT_MAX))->toBeFalse();
});
