<?php

declare(strict_types=1);

use Modules\User\Services\RestaurantProductTypeResolver;

it('classifies main dishes as meals', function (): void {
    $resolver = app(RestaurantProductTypeResolver::class);

    expect($resolver->resolve(null, 'الأطباق الرئيسية', 'ستيك لحم بقري'))->toBe('meal')
        ->and($resolver->resolve(null, 'الطبق الرئيسي', 'باستا كاربونارا'))->toBe('meal');
});

it('keeps specific food types separate from generic meals', function (): void {
    $resolver = app(RestaurantProductTypeResolver::class);

    expect($resolver->resolve(null, 'الطبق الرئيسي', 'برغر دجاج'))->toBe('burger')
        ->and($resolver->resolve(null, 'الطبق الرئيسي', 'ساندويش كريسبي'))->toBe('sandwich')
        ->and($resolver->resolve(null, 'مقبلات', 'كاليماري مقرمش'))->toBe('appetizer');
});

it('treats dish-like types as compatible with a requested meal but not sandwiches', function (): void {
    $resolver = app(RestaurantProductTypeResolver::class);

    expect($resolver->matches('meal', 'meal'))->toBeTrue()
        ->and($resolver->matches('meal', 'dish'))->toBeTrue()
        ->and($resolver->matches('meal', 'combo'))->toBeTrue()
        ->and($resolver->matches('meal', 'sandwich'))->toBeFalse()
        ->and($resolver->matches('sandwich', 'meal'))->toBeFalse();
});
