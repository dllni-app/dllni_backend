<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3PublishedFormValidator;
use Modules\Cleaning\Services\CleaningV3VisitPlanException;

function publishedV3Fields(): array
{
    return [
        [
            'key' => 'property.area', 'componentId' => 'numeric',
            'required' => true, 'supportedByServer' => true,
            'constraints' => ['min' => 10, 'max' => 300],
            'choices' => [],
        ],
        [
            'key' => 'room.count', 'componentId' => 'stepper',
            'required' => true, 'supportedByServer' => true,
            'constraints' => ['min' => 1, 'max' => 20],
            'choices' => [],
        ],
        [
            'key' => 'service.kind', 'componentId' => 'select',
            'required' => true, 'supportedByServer' => true,
            'constraints' => [], 'choices' => [
                ['key' => 'regular'], ['key' => 'deep'],
            ],
        ],
        [
            'key' => 'addons.list', 'componentId' => 'multiselect',
            'required' => false, 'supportedByServer' => true,
            'constraints' => ['maxSelections' => 2],
            'choices' => [['key' => 'carpet'], ['key' => 'windows'], ['key' => 'furniture']],
        ],
    ];
}

test('V3 only normalizes controls approved by the exact published item map', function (): void {
    $answers = [
        'room.count' => 3,
        'property.area' => '70',
        'service.kind' => 'deep',
        'addons.list' => ['carpet', 'windows'],
    ];
    $normalized = (new CleaningV3PublishedFormValidator)->normalize(publishedV3Fields(), $answers);
    expect(array_keys($normalized))->toBe(['addons.list', 'property.area', 'room.count', 'service.kind'])
        ->and($normalized['property.area'])->toBe(70.0)
        ->and($normalized['room.count'])->toBe(3)
        ->and($normalized['service.kind'])->toBe('deep');
});

test('V3 required and range constraints are enforced server-side', function (): void {
    $validator = new CleaningV3PublishedFormValidator;
    expect(fn () => $validator->normalize(publishedV3Fields(), ['room.count' => 2]))
        ->toThrow(CleaningV3VisitPlanException::class, 'REQUIRED_PUBLISHED_FIELD_MISSING');
    expect(fn () => $validator->normalize(publishedV3Fields(), [
        'property.area' => 5, 'room.count' => 2, 'service.kind' => 'deep',
    ]))->toThrow(CleaningV3VisitPlanException::class, 'NUMBER_OUT_OF_RANGE');
    expect(fn () => $validator->normalize(publishedV3Fields(), [
        'property.area' => 70, 'room.count' => 1.5, 'service.kind' => 'deep',
    ]))->toThrow(CleaningV3VisitPlanException::class, 'NUMBER_OUT_OF_RANGE');
});

test('V3 rejects client-invented choices, unknown keys and duplicate selection', function (): void {
    $validator = new CleaningV3PublishedFormValidator;
    $valid = ['property.area' => 70, 'room.count' => 2, 'service.kind' => 'deep'];
    expect(fn () => $validator->normalize(publishedV3Fields(), [...$valid, 'service.kind' => 'fake']))
        ->toThrow(CleaningV3VisitPlanException::class, 'INVALID_CHOICE');
    expect(fn () => $validator->normalize(publishedV3Fields(), [...$valid, 'price' => 0]))
        ->toThrow(CleaningV3VisitPlanException::class, 'UNKNOWN_CATALOG_FIELD');
    expect(fn () => $validator->normalize(publishedV3Fields(), [...$valid, 'addons.list' => ['carpet', 'carpet']]))
        ->toThrow(CleaningV3VisitPlanException::class, 'INVALID_MULTISELECTION');
});

test('V3 required unsupported component blocks planning, never omitted', function (): void {
    $items = publishedV3Fields();
    $items[] = [
        'key' => 'advanced.room_builder', 'componentId' => 'room_builder',
        'required' => true, 'supportedByServer' => true,
        'constraints' => [], 'choices' => [],
    ];
    expect(fn () => (new CleaningV3PublishedFormValidator)->normalize($items, [
        'property.area' => 70, 'room.count' => 2, 'service.kind' => 'deep',
    ]))->toThrow(CleaningV3VisitPlanException::class, 'PUBLISHED_CONTROL_UNSUPPORTED');
});

test('V3 optional unsupported values, duplicated catalog keys and conditional rules are rejected', function (): void {
    $items = publishedV3Fields();
    $items[] = [
        'key' => 'future.location', 'componentId' => 'location',
        'required' => false, 'supportedByServer' => true,
        'constraints' => [], 'choices' => [],
    ];
    $valid = ['property.area' => 70, 'room.count' => 2, 'service.kind' => 'deep'];
    expect(fn () => (new CleaningV3PublishedFormValidator)->normalize($items, [...$valid, 'future.location' => ['lat' => 36]]))
        ->toThrow(CleaningV3VisitPlanException::class, 'PUBLISHED_CONTROL_UNSUPPORTED');

    $items = publishedV3Fields();
    $items[] = $items[0];
    expect(fn () => (new CleaningV3PublishedFormValidator)->normalize($items, $valid))
        ->toThrow(CleaningV3VisitPlanException::class, 'INVALID_PUBLISHED_FIELD_KEY');

    $items = publishedV3Fields();
    $items[0]['visibleWhen'] = ['service.kind' => 'deep'];
    expect(fn () => (new CleaningV3PublishedFormValidator)->normalize($items, $valid))
        ->toThrow(CleaningV3VisitPlanException::class, 'UNAPPROVED_CONDITIONAL_RULE');
});

test('V3 numeric step requires alignment with published step and lower bound', function (): void {
    $items = publishedV3Fields();
    $items[0]['constraints']['step'] = 5;
    $valid = ['property.area' => '15', 'room.count' => 2, 'service.kind' => 'deep'];
    expect((new CleaningV3PublishedFormValidator)->normalize($items, $valid)['property.area'])->toBe(15.0);
    $valid['property.area'] = '17';
    expect(fn () => (new CleaningV3PublishedFormValidator)->normalize($items, $valid))
        ->toThrow(CleaningV3VisitPlanException::class, 'NUMBER_STEP_MISMATCH');
});

test('V3 rejects scientific notation, excess decimal places, leading zeros and invalid numeric formats', function (): void {
    $validator = new CleaningV3PublishedFormValidator;
    $valid = ['property.area' => '70', 'room.count' => 2, 'service.kind' => 'deep'];

    foreach (['7e1', '70.001', '070', ' 70 ', '+70', '70,5', 'NaN', '-70', '10000000000', '70.'] as $bad) {
        expect(fn () => $validator->normalize(publishedV3Fields(), [...$valid, 'property.area' => $bad]))
            ->toThrow(CleaningV3VisitPlanException::class, 'INVALID_NUMBER');
    }

    expect($validator->normalize(publishedV3Fields(), [...$valid, 'property.area' => '70.25'])['property.area'])
        ->toBe(70.25);
});
