<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CapacityException;
use Modules\Cleaning\Services\CleaningV3PublishedWorkloadPreviewService;

uses(RefreshDatabase::class);

/** @return array{0:CleaningV3CatalogVersion,1:string} */
function v3WorkloadCatalog(string $unit = 'piece', string $effort = '250.00'): array
{
    $source = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'qa_capacity_catalog',
        'booking_value' => 'qa_capacity_catalog',
        'title' => 'مخزون حساب القدرة',
        'is_active' => true,
    ]);
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'QA capacity']);
    $item = $version->items()->create([
        'item_key' => 'restaurant.equipment',
        'source_type' => 'property',
        'source_id' => $source->id,
        'label_ar' => 'تجهيزات المطعم',
        'measurement_mode' => $unit,
        'required' => true,
        'is_enabled' => true,
    ]);
    $item->tariffRules()->create([
        'rule_key' => 'regular',
        'cleaning_mode' => 'regular',
        'scope_key' => 'whole',
        'quantity_min' => '0',
        'quantity_max' => '60',
        'unit_price' => '5000.00',
        'unit_effort_minutes' => $effort,
        'is_enabled' => true,
    ]);

    $digest = str_repeat('e', 64);
    DB::table('cleaning_v3_catalog_versions')->where('id', $version->id)->update([
        'status' => 'published',
        'published_digest' => $digest,
    ]);

    return [$version->fresh(), $digest];
}

function v3WorkloadLine(string $quantity): array
{
    return [
        'itemKey' => 'restaurant.equipment',
        'cleaningMode' => 'regular',
        'scopeKey' => 'whole',
        'quantity' => $quantity,
    ];
}

test('three indivisible 250-minute pieces require three workers rather than ceil 750/480', function (): void {
    [$version, $digest] = v3WorkloadCatalog();
    $result = app(CleaningV3PublishedWorkloadPreviewService::class)->preview(
        $version, $digest, [v3WorkloadLine('3')],
    );

    expect($result['estimatedBaseAmountSyp'])->toBe('15000.00')
        ->and($result['estimatedEffortMinutes'])->toBe('750.00')
        ->and($result['capacity']['estimatedWorkers'])->toBe(3)
        ->and($result['capacity']['totalEffortMinutes'])->toBe(750)
        ->and(count($result['capacity']['workerLoads']))->toBe(3)
        ->and($result['canQuote'])->toBeFalse()
        ->and($result['canBook'])->toBeFalse()
        ->and($result['canSettle'])->toBeFalse();
});

test('a chosen team gets a derived duration with the same immutable price', function (): void {
    [$version, $digest] = v3WorkloadCatalog('piece', '100.00');
    $planner = app(CleaningV3PublishedWorkloadPreviewService::class);
    foreach ([1 => 300, 2 => 200, 3 => 100] as $workers => $expected) {
        $preview = $planner->previewForm($version, $digest, ['restaurant.equipment' => 3], 'regular', requestedWorkers: $workers);
        expect($preview['requestedWorkers'])->toBe($workers)
            ->and(max(array_column($preview['capacity']['workerLoads'], 'effortMinutes')))->toBe($expected)
            ->and($preview['estimatedBaseAmountSyp'])->toBe('15000.00')
            ->and($preview['canBook'])->toBeFalse();
    }
});

test('an impossible two-worker or fractional-piece job fails safely', function (): void {
    [$version, $digest] = v3WorkloadCatalog();
    $planner = app(CleaningV3PublishedWorkloadPreviewService::class);

    expect(fn () => $planner->preview($version, $digest, [v3WorkloadLine('3')], 2))
        ->toThrow(CleaningV3CapacityException::class, 'CAPACITY_INFEASIBLE');
    expect(fn () => $planner->preview($version, $digest, [v3WorkloadLine('3.50')]))
        ->toThrow(DomainException::class, 'V3_PUBLISHED_MEASUREMENT_INVALID');
});

test('unapproved area splitting cannot convert a 750-minute atomic surface into a fake valid visit', function (): void {
    [$version, $digest] = v3WorkloadCatalog('sqm');
    expect(fn () => app(CleaningV3PublishedWorkloadPreviewService::class)
        ->preview($version, $digest, [v3WorkloadLine('3')]))
        ->toThrow(CleaningV3CapacityException::class, 'CAPACITY_INFEASIBLE');
});

test('fractional unit effort rounds upward per indivisible piece but never invents a price', function (): void {
    [$version, $digest] = v3WorkloadCatalog('count', '1.25');
    $result = app(CleaningV3PublishedWorkloadPreviewService::class)->preview(
        $version, $digest, [v3WorkloadLine('3')],
    );
    expect($result['estimatedBaseAmountSyp'])->toBe('15000.00')
        ->and($result['estimatedEffortMinutes'])->toBe('3.75')
        ->and($result['capacity']['totalEffortMinutes'])->toBe(6)
        ->and($result['capacity']['estimatedWorkers'])->toBe(1);
});

test('the published plan refuses unbounded task counts without billing or dispatch side effects', function (): void {
    [$version, $digest] = v3WorkloadCatalog('piece', '10.00');
    expect(fn () => app(CleaningV3PublishedWorkloadPreviewService::class)
        ->preview($version, $digest, [v3WorkloadLine('41')]))
        ->toThrow(CleaningV3CapacityException::class, 'CAPACITY_CHECK_UNAVAILABLE');
});
