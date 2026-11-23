<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogItem;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CatalogDraftEstimateService;

uses(RefreshDatabase::class);

/** @return array{0:CleaningV3CatalogVersion,1:CleaningV3CatalogItem,2:CleaningHomeType} */
function v3EstimateFixture(): array
{
    $source = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'estimate_v3_qa_property',
        'booking_value' => 'estimate_v3_qa_property',
        'title' => 'منشأة تجريبية',
        'is_active' => true,
    ]);
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'V3 pricing draft preview']);
    $item = $version->items()->create([
        'item_key' => 'restaurant.kitchen',
        'source_type' => 'property',
        'source_id' => $source->id,
        'label_ar' => 'مطبخ مطعم',
        'measurement_mode' => 'sqm',
        'is_enabled' => true,
    ]);

    return [$version, $item, $source];
}

function v3EstimateRule(
    CleaningV3CatalogItem $item,
    string $key,
    string $mode,
    string $scope,
    string $minimum,
    string $maximum,
    string $price,
    string $effort,
): void {
    $item->tariffRules()->create([
        'rule_key' => $key,
        'cleaning_mode' => $mode,
        'scope_key' => $scope,
        'quantity_min' => $minimum,
        'quantity_max' => $maximum,
        'unit_price' => $price,
        'unit_effort_minutes' => $effort,
    ]);
}

test('draft preview uses exact decimal arithmetic with one half-up rounding per item line', function (): void {
    [$version, $item] = v3EstimateFixture();
    v3EstimateRule($item, 'small', 'regular', 'whole', '0', '2', '10.05', '7.25');

    $estimate = app(CleaningV3CatalogDraftEstimateService::class)->estimate($version, [[
        'itemKey' => 'restaurant.kitchen',
        'cleaningMode' => 'regular',
        'scopeKey' => 'whole',
        'quantity' => '0.50',
    ]]);

    expect($estimate['estimatedBaseAmountSyp'])->toBe('5.03')
        ->and($estimate['estimatedEffortMinutes'])->toBe('3.63')
        ->and($estimate['currency'])->toBe('SYP')
        ->and($estimate['items'][0]['unitPriceSyp'])->toBe('10.05')
        ->and($estimate['items'][0]['quantity'])->toBe('0.50')
        ->and($estimate['canQuote'])->toBeFalse()
        ->and($estimate['canBook'])->toBeFalse()
        ->and($estimate['canSettle'])->toBeFalse();

    expect($item->fresh()->tariffRules()->count())->toBe(1)
        ->and($version->fresh()->status)->toBe('draft');
});

test('two deep cleaning scopes aggregate without charging a duplicate minimum per scope', function (): void {
    [$version, $item] = v3EstimateFixture();
    v3EstimateRule($item, 'floors', 'deep', 'floors', '0', '100', '100.00', '15.00');
    v3EstimateRule($item, 'walls', 'deep', 'walls', '0', '100', '60.00', '8.00');

    $estimate = app(CleaningV3CatalogDraftEstimateService::class)->estimate($version, [
        ['itemKey' => $item->item_key, 'cleaningMode' => 'deep', 'scopeKey' => 'floors', 'quantity' => '12'],
        ['itemKey' => $item->item_key, 'cleaningMode' => 'deep', 'scopeKey' => 'walls', 'quantity' => '4'],
    ]);

    expect($estimate['estimatedBaseAmountSyp'])->toBe('1440.00')
        ->and($estimate['estimatedEffortMinutes'])->toBe('212.00')
        ->and($estimate['items'])->toHaveCount(2)
        ->and($estimate['requiresCapacityApproval'])->toBeTrue();
});

test('missing and ambiguous draft tariffs fail instead of choosing an arbitrary price', function (): void {
    [$version, $item] = v3EstimateFixture();
    $service = app(CleaningV3CatalogDraftEstimateService::class);
    $input = [[
        'itemKey' => $item->item_key,
        'cleaningMode' => 'regular',
        'scopeKey' => 'whole',
        'quantity' => '12.00',
    ]];

    expect(fn () => $service->estimate($version, $input))
        ->toThrow(DomainException::class, 'V3_DRAFT_TARIFF_COVERAGE_MISSING');

    v3EstimateRule($item, 'low', 'regular', 'whole', '0', '20', '300', '10');
    v3EstimateRule($item, 'overlap', 'regular', 'whole', '10', '30', '700', '15');

    expect(fn () => $service->estimate($version, $input))
        ->toThrow(DomainException::class, 'V3_DRAFT_TARIFF_AMBIGUOUS');

    $input[0]['quantity'] = '30.00';
    expect(fn () => $service->estimate($version, $input))
        ->toThrow(DomainException::class, 'V3_DRAFT_TARIFF_COVERAGE_MISSING');
});

test('invalid quantity, unapproved item and repeated scope cannot create even a preview', function (): void {
    [$version, $item] = v3EstimateFixture();
    v3EstimateRule($item, 'single', 'regular', 'whole', '0', '100', '500', '15');
    $service = app(CleaningV3CatalogDraftEstimateService::class);
    $valid = [
        'itemKey' => $item->item_key,
        'cleaningMode' => 'regular',
        'scopeKey' => 'whole',
        'quantity' => '4',
    ];

    foreach ([
        ['quantity' => 4],
        ['quantity' => '-1'],
        ['quantity' => '0'],
        ['quantity' => '1.000'],
        ['quantity' => '100.00'],
        ['cleaningMode' => 'unknown'],
        ['scopeKey' => 'private/path'],
        ['itemKey' => 'customer.injected'],
    ] as $override) {
        expect(fn () => $service->estimate($version, [array_merge($valid, $override)]))
            ->toThrow(DomainException::class);
    }

    expect(fn () => $service->estimate($version, [$valid, $valid]))
        ->toThrow(DomainException::class, 'V3_DRAFT_PREVIEW_DUPLICATE_SCOPE');
});

test('inactive sources and externally published catalogs refuse draft price previews', function (): void {
    [$version, $item, $source] = v3EstimateFixture();
    v3EstimateRule($item, 'rate', 'regular', 'whole', '0', '100', '500', '15');
    $service = app(CleaningV3CatalogDraftEstimateService::class);
    $input = [[
        'itemKey' => $item->item_key,
        'cleaningMode' => 'regular',
        'scopeKey' => 'whole',
        'quantity' => '10',
    ]];

    $source->update(['is_active' => false]);
    expect(fn () => $service->estimate($version, $input))
        ->toThrow(Illuminate\Validation\ValidationException::class);

    $source->update(['is_active' => true]);
    Illuminate\Support\Facades\DB::table('cleaning_v3_catalog_versions')
        ->where('id', $version->id)->update(['status' => 'published']);
    expect(fn () => $service->estimate($version->fresh(), $input))
        ->toThrow(DomainException::class, 'V3_DRAFT_REQUIRED');
});

test('Filament offers a safe preview action and does not create a quote or payment', function (): void {
    [$version, $item] = v3EstimateFixture();
    v3EstimateRule($item, 'qa-rate', 'regular', 'whole', '0', '100', '100', '8');
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    $admin = App\Models\User::factory()->create();
    $admin->assignRole(Spatie\Permission\Models\Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin);

    Livewire\Livewire::test(
        App\Filament\Resources\CleaningV3CatalogVersions\Pages\EditCleaningV3CatalogVersion::class,
        ['record' => $version->getRouteKey()],
    )
        ->assertActionExists('previewDraftTariff')
        ->callAction('previewDraftTariff', data: [
            'itemKey' => $item->item_key,
            'cleaningMode' => 'regular',
            'scopeKey' => 'whole',
            'quantity' => '1.50',
        ])
        ->assertHasNoFormErrors();

    expect($version->fresh()->status)->toBe('draft')
        ->and($version->fresh()->published_at)->toBeNull()
        ->and(Illuminate\Support\Facades\DB::table('cleaning_booking_quotes')->count())->toBe(0)
        ->and(Illuminate\Support\Facades\DB::table('cleaning_visit_funding_reservations')->count())->toBe(0);
});
