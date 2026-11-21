<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3CatalogPublicSerializer;

function makeV3SerializerFakeItem(array $rules): object
{
    return (object) [
        'input_rules' => $rules,
        'measurement_mode' => 'sqm',
        'label_ar' => 'مساحة',
        'label_en' => 'Area',
        'item_key' => 'property.area',
        'source_type' => 'property',
        'source_id' => 1,
        'required' => true,
        'presets' => collect([]),
    ];
}

test('V3 serializer keeps simple safe numeric constraints while dropping internal pricing fields', function (): void {
    $serializer = new CleaningV3CatalogPublicSerializer;
    $item = makeV3SerializerFakeItem(['componentId' => 'numeric', 'min' => 10, 'max' => 300]);
    $method = new ReflectionMethod(CleaningV3CatalogPublicSerializer::class, 'item');
    $data = $method->invoke($serializer, $item, 'ar');

    expect($data['label'])->toBe('مساحة')
        ->and($data['componentId'])->toBe('numeric')
        ->and($data['supportedByServer'])->toBeTrue()
        ->and($data['unsupportedRules'])->toBeFalse()
        ->and($data['constraints']['min'])->toBe(10.0);
});

test('V3 serializer visibly marks unknown conditional and financial metadata unsupported', function (): void {
    $serializer = new CleaningV3CatalogPublicSerializer;
    $item = makeV3SerializerFakeItem([
        'componentId' => 'numeric',
        'min' => 10,
        'visibleWhen' => ['service.kind' => 'deep'],
        'internalPrice' => 100000,
    ]);
    $method = new ReflectionMethod(CleaningV3CatalogPublicSerializer::class, 'item');
    $data = $method->invoke($serializer, $item, 'ar');

    expect($data['supportedByServer'])->toBeFalse()
        ->and($data['unsupportedRules'])->toBeTrue()
        ->and(json_encode($data))->not->toContain('internalPrice', '100000', 'visibleWhen');
});
