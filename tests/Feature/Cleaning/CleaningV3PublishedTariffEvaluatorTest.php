<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3PublishedTariffEvaluator;

uses(RefreshDatabase::class);

/** @return array{0:CleaningV3CatalogVersion,1:string} */
function v3PublishedTariffFixture(): array
{
    $property = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'qa_v3_published_tariff',
        'booking_value' => 'qa_v3_published_tariff',
        'title' => 'منشأة اختبار التسعير',
        'is_active' => true,
    ]);
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'QA tariff version']);
    $item = $version->items()->create([
        'item_key' => 'restaurant.kitchen',
        'source_type' => 'property',
        'source_id' => $property->id,
        'label_ar' => 'مطبخ مطعم',
        'measurement_mode' => 'sqm',
        'is_enabled' => true,
        'required' => true,
    ]);
    foreach ([
        ['regular', 'whole', 'small', '0', '10', '100.00', '20.00'],
        ['regular', 'whole', 'large', '10', '60', '80.00', '16.00'],
        ['deep', 'floors', 'floors', '0', '60', '200.00', '30.00'],
        ['deep', 'walls', 'walls', '0', '60', '90.00', '15.00'],
    ] as [$mode, $scope, $key, $min, $max, $price, $effort]) {
        $item->tariffRules()->create([
            'rule_key' => $key,
            'cleaning_mode' => $mode,
            'scope_key' => $scope,
            'quantity_min' => $min,
            'quantity_max' => $max,
            'unit_price' => $price,
            'unit_effort_minutes' => $effort,
            'is_enabled' => true,
        ]);
    }

    $digest = str_repeat('d', 64);
    // No public publisher exists while approvals are missing. Simulate
    // a historically approved immutable snapshot ONLY in the QA database.
    DB::table('cleaning_v3_catalog_versions')->where('id', $version->id)->update([
        'status' => CleaningV3CatalogVersion::PUBLISHED,
        'published_digest' => $digest,
    ]);

    return [$version->fresh(), $digest];
}

test('published tariff exact tiers and deep scope prices are read only and cents exact', function (): void {
    [$version, $digest] = v3PublishedTariffFixture();
    $result = app(CleaningV3PublishedTariffEvaluator::class)->evaluate($version, $digest, [
        ['itemKey' => 'restaurant.kitchen', 'cleaningMode' => 'regular', 'scopeKey' => 'whole', 'quantity' => '10'],
        ['itemKey' => 'restaurant.kitchen', 'cleaningMode' => 'deep', 'scopeKey' => 'floors', 'quantity' => '0.25'],
        ['itemKey' => 'restaurant.kitchen', 'cleaningMode' => 'deep', 'scopeKey' => 'walls', 'quantity' => '1.50'],
    ]);

    expect($result['catalogRevision'])->toBe($digest)
        ->and($result['estimatedBaseAmountSyp'])->toBe('985.00')
        ->and($result['estimatedEffortMinutes'])->toBe('190.00')
        ->and(array_column($result['lines'], 'ruleKey'))->toBe(['large', 'floors', 'walls'])
        ->and(array_column($result['lines'], 'lineAmountSyp'))->toBe(['800.00', '50.00', '135.00'])
        ->and($result['canQuote'])->toBeFalse()
        ->and($result['canBook'])->toBeFalse()
        ->and($result['canSettle'])->toBeFalse();
    expect($version->fresh()->status)->toBe('published')
        ->and($version->items()->firstOrFail()->tariffRules()->count())->toBe(4);
});

test('draft, retired or wrong revision never silently become a live tariff', function (): void {
    [$version, $digest] = v3PublishedTariffFixture();
    $lines = [['itemKey' => 'restaurant.kitchen', 'cleaningMode' => 'regular', 'scopeKey' => 'whole', 'quantity' => '3']];

    expect(fn () => app(CleaningV3PublishedTariffEvaluator::class)
        ->evaluate($version, str_repeat('a', 64), $lines))
        ->toThrow(DomainException::class, 'V3_PUBLISHED_CATALOG_REQUIRED');

    DB::table('cleaning_v3_catalog_versions')->where('id', $version->id)
        ->update(['status' => 'retired']);
    expect(fn () => app(CleaningV3PublishedTariffEvaluator::class)
        ->evaluate($version->fresh(), $digest, $lines))
        ->toThrow(DomainException::class, 'V3_PUBLISHED_CATALOG_REQUIRED');
});

test('missing quantity tiers, unsupported scopes and duplicate charges are blocked', function (): void {
    [$version, $digest] = v3PublishedTariffFixture();
    $evaluator = app(CleaningV3PublishedTariffEvaluator::class);

    foreach ([
        [['itemKey' => 'restaurant.kitchen', 'cleaningMode' => 'regular', 'scopeKey' => 'whole', 'quantity' => '60']],
        [['itemKey' => 'restaurant.kitchen', 'cleaningMode' => 'deep', 'scopeKey' => 'ceilings', 'quantity' => '3']],
        [['itemKey' => 'unknown', 'cleaningMode' => 'regular', 'scopeKey' => 'whole', 'quantity' => '3']],
    ] as $lines) {
        expect(fn () => $evaluator->evaluate($version, $digest, $lines))
            ->toThrow(DomainException::class);
    }

    $same = ['itemKey' => 'restaurant.kitchen', 'cleaningMode' => 'regular', 'scopeKey' => 'whole', 'quantity' => '3'];
    expect(fn () => $evaluator->evaluate($version, $digest, [$same, $same]))
        ->toThrow(DomainException::class, 'DUPLICATE_V3_TARIFF_SCOPE');
});

test('published evaluator rejects client price overrides and noncanonical quantities', function (): void {
    [$version, $digest] = v3PublishedTariffFixture();
    $evaluator = app(CleaningV3PublishedTariffEvaluator::class);
    $line = ['itemKey' => 'restaurant.kitchen', 'cleaningMode' => 'regular', 'scopeKey' => 'whole', 'quantity' => '3'];

    expect(fn () => $evaluator->evaluate($version, $digest, [[...$line, 'unitPrice' => '0.00']]))
        ->toThrow(DomainException::class, 'INVALID_V3_TARIFF_LINES');

    foreach (['1e2', '003', '2.333', '-1', '0', ' 2 ', 'NaN'] as $quantity) {
        expect(fn () => $evaluator->evaluate($version, $digest, [
            [...$line, 'quantity' => $quantity],
        ]))->toThrow(DomainException::class, 'INVALID_V3_TARIFF_LINES');
    }
});

test('ambiguous approved published tariff rules fail rather than guessing a price', function (): void {
    [$version, $digest] = v3PublishedTariffFixture();
    $item = $version->items()->firstOrFail();
    // Historical published snapshots are immutable through Eloquent; QA
    // simulates a corrupted persisted tariff to exercise fail-closed reads.
    DB::table('cleaning_v3_catalog_tariff_rules')->insert([
        'catalog_item_id' => $item->id,
        'rule_key' => 'overlap-corrupt',
        'cleaning_mode' => 'regular',
        'scope_key' => 'whole',
        'quantity_min' => '4',
        'quantity_max' => '12',
        'unit_price' => '10.00',
        'unit_effort_minutes' => '10.00',
        'currency' => 'SYP',
        'is_enabled' => true,
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(CleaningV3PublishedTariffEvaluator::class)->evaluate(
        $version, $digest,
        [['itemKey' => 'restaurant.kitchen', 'cleaningMode' => 'regular', 'scopeKey' => 'whole', 'quantity' => '5']],
    ))->toThrow(DomainException::class, 'V3_PUBLISHED_TARIFF_AMBIGUOUS');
});
