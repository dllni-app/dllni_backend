<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogItem;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CatalogDraftValidator;
use Modules\Cleaning\Services\CleaningV3CatalogPublicSerializer;

uses(RefreshDatabase::class);

/** @return array{0:CleaningV3CatalogVersion,1:CleaningV3CatalogItem} */
function tariffDraftFixture(): array
{
    $source = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'qa_v3_tariff_sample',
        'booking_value' => 'qa_v3_tariff_sample',
        'title' => 'اختبار التسعير',
        'is_active' => true,
    ]);
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'Tariff QA draft']);
    $item = $version->items()->create([
        'item_key' => 'restaurant.kitchen',
        'source_type' => 'property',
        'source_id' => $source->id,
        'label_ar' => 'مطبخ تجريبي',
        'measurement_mode' => 'sqm',
        'is_enabled' => true,
        'required' => true,
    ]);

    return [$version, $item];
}

function tariffDraftRule(CleaningV3CatalogItem $item, string $key, string $from, string $to, string $mode = 'regular', string $scope = 'whole'): void
{
    $item->tariffRules()->create([
        'rule_key' => $key,
        'cleaning_mode' => $mode,
        'scope_key' => $scope,
        'quantity_min' => $from,
        'quantity_max' => $to,
        'unit_price' => '150000.00',
        'unit_effort_minutes' => '25.00',
        'is_enabled' => true,
    ]);
}

test('a V3 catalog draft stores money and effort rules separately without changing V2 prices', function (): void {
    [$version, $item] = tariffDraftFixture();
    $before = CleaningHomeType::query()->findOrFail($item->source_id)->getRawOriginal('price');

    tariffDraftRule($item, 'regular-small', '0', '30');
    tariffDraftRule($item, 'regular-large', '30', '90');
    tariffDraftRule($item, 'deep-floor', '0', '90', 'deep', 'floors');
    tariffDraftRule($item, 'deep-wall', '0', '90', 'deep', 'walls');

    expect($item->tariffRules()->count())->toBe(4)
        ->and((string) $item->tariffRules()->first()->unit_price)->toBe('150000.00')
        ->and((string) $item->tariffRules()->first()->unit_effort_minutes)->toBe('25.00')
        ->and(CleaningHomeType::query()->find($item->source_id)->getRawOriginal('price'))->toBe($before);

    $review = app(CleaningV3CatalogDraftValidator::class)->review($version);
    expect($review)->not->toContain('TARIFF_MISSING:restaurant.kitchen')
        ->not->toContain('OVERLAPPING_TARIFFS:restaurant.kitchen')
        ->not->toContain('TARIFF_GAP:restaurant.kitchen')
        ->toContain('APPROVED_V3_PRICING_AND_FUNDING_PROOF_MISSING');

    $encoded = json_encode(app(CleaningV3CatalogPublicSerializer::class)->serialize($version, 'ar'));
    expect($encoded)->not->toContain('150000.00', 'unit_price', 'unit_effort_minutes');
    expect($version->fresh()->status)->toBe('draft')
        ->and($version->published_digest)->toBeNull();
});

test('rules reject zero or invalid price, duration, quantity, currency and mode', function (): void {
    [, $item] = tariffDraftFixture();
    $base = [
        'rule_key' => 'valid-rate',
        'cleaning_mode' => 'regular',
        'scope_key' => 'whole',
        'quantity_min' => '0',
        'quantity_max' => '20',
        'unit_price' => '150000.00',
        'unit_effort_minutes' => '20',
    ];

    foreach ([
        ['unit_price' => '0'],
        ['unit_price' => '-50'],
        ['unit_price' => '150.000'],
        ['unit_effort_minutes' => '0'],
        ['unit_effort_minutes' => '481'],
        ['quantity_max' => '0'],
        ['quantity_max' => '-1'],
        ['cleaning_mode' => 'unsupported'],
        ['scope_key' => '../private'],
    ] as $override) {
        expect(fn () => $item->tariffRules()->create(array_merge($base, $override)))
            ->toThrow(ValidationException::class);
    }

    $rule = $item->tariffRules()->create(array_merge($base, [
        'rule_key' => 'valid-currency',
    ]));
    expect(fn () => $rule->forceFill(['currency' => 'USD'])->save())
        ->toThrow(ValidationException::class);
});

test('review reports competing overlapping price ranges and gaps without enabling unsafe publication', function (): void {
    [$version, $item] = tariffDraftFixture();
    $validator = app(CleaningV3CatalogDraftValidator::class);

    expect($validator->review($version))->toContain('TARIFF_MISSING:restaurant.kitchen');

    tariffDraftRule($item, 'tier-a', '0', '20');
    tariffDraftRule($item, 'tier-overlap', '10', '30');
    tariffDraftRule($item, 'tier-gap', '40', '60');

    $review = $validator->review($version);
    expect($review)->toContain(
        'OVERLAPPING_TARIFFS:restaurant.kitchen',
        'TARIFF_GAP:restaurant.kitchen',
        'APPROVED_CATALOG_COVERAGE_MATRIX_MISSING',
    );
    expect(fn () => $validator->publish($version))->toThrow(DomainException::class);
});

test('tariff edits are disallowed once the version is published, including edit and delete', function (): void {
    [$version, $item] = tariffDraftFixture();
    tariffDraftRule($item, 'rule-one', '0', '20');
    $rule = $item->tariffRules()->firstOrFail();

    DB::table('cleaning_v3_catalog_versions')
        ->where('id', $version->id)->update(['status' => 'published']);
    expect(fn () => $rule->update(['unit_price' => '50.00']))
        ->toThrow(LogicException::class);
    expect(fn () => $rule->delete())->toThrow(LogicException::class);
    expect(DB::table('cleaning_v3_catalog_tariff_rules')
        ->where('catalog_item_id', $item->id)->count())->toBe(1);
});

test('tariff rule identities remain unique per item', function (): void {
    [, $item] = tariffDraftFixture();
    tariffDraftRule($item, 'same-code', '0', '20');
    expect(fn () => tariffDraftRule($item, 'same-code', '20', '40'))
        ->toThrow(Illuminate\Database\QueryException::class);
});

test('authorized Filament catalog editor exposes the draft tariff and effort relation without publishing it', function (): void {
    [$version, $item] = tariffDraftFixture();
    tariffDraftRule($item, 'preview-tier', '0', '20');

    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = App\Models\User::factory()->create();
    $admin->assignRole(Spatie\Permission\Models\Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin);

    $url = App\Filament\Resources\CleaningV3CatalogVersions\CleaningV3CatalogVersionResource::getUrl(
        'edit', ['record' => $version], isAbsolute: false,
    );
    $this->get($url)->assertOk();

    // Filament renders relationship action forms lazily, not in the initial
    // edit-page HTTP response. Exercise the relation manager's actual action.
    Livewire\Livewire::test(
        App\Filament\Resources\CleaningV3CatalogVersions\RelationManagers\ItemsRelationManager::class,
        [
            'ownerRecord' => $version,
            'pageClass' => App\Filament\Resources\CleaningV3CatalogVersions\Pages\EditCleaningV3CatalogVersion::class,
        ],
    )->mountTableAction('create')->assertSee('قواعد السعر والجهد');

    expect($version->fresh()->status)->toBe('draft')
        ->and($version->published_at)->toBeNull();
});

test('draft readiness detects unsupported mandatory components and invalid dynamic input rules before publication', function (): void {
    [$version, $item] = tariffDraftFixture();
    tariffDraftRule($item, 'valid-base', '0', '90');

    $item->update(['input_rules' => [
        'componentId' => 'room_builder',
        'min' => 10,
        'max' => 5,
        'step' => 0,
        'hiddenConditionalRule' => ['client' => 'deep'],
    ]]);

    $problems = app(CleaningV3CatalogDraftValidator::class)->review($version);
    expect($problems)->toContain(
        'UNSUPPORTED_INPUT_RULES:restaurant.kitchen',
        'REQUIRED_COMPONENT_UNSUPPORTED:restaurant.kitchen',
        'INVALID_INPUT_CONSTRAINTS:restaurant.kitchen',
        'APPROVED_CATALOG_COVERAGE_MATRIX_MISSING',
        'APPROVED_V3_PRICING_AND_FUNDING_PROOF_MISSING',
    );

    expect($version->fresh()->status)->toBe('draft')
        ->and($version->fresh()->published_digest)->toBeNull();
});

test('Filament readiness action reviews actual draft issues without publishing or changing prices', function (): void {
    [$version, $item] = tariffDraftFixture();
    tariffDraftRule($item, 'valid-base', '0', '90');

    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = App\Models\User::factory()->create();
    $admin->assignRole(Spatie\Permission\Models\Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin);

    Livewire\Livewire::test(
        App\Filament\Resources\CleaningV3CatalogVersions\Pages\EditCleaningV3CatalogVersion::class,
        ['record' => $version->getRouteKey()],
    )->callAction('reviewDraftReadiness')->assertHasNoErrors();

    expect($version->fresh()->status)->toBe('draft')
        ->and($version->fresh()->published_at)->toBeNull()
        ->and($version->fresh()->published_digest)->toBeNull()
        ->and($item->tariffRules()->firstOrFail()->unit_price)->toBe('150000.00');
});

test('draft tariff review catches unpriced lower and inclusive upper form boundaries', function (): void {
    [$version, $item] = tariffDraftFixture();
    $item->update(['input_rules' => ['componentId' => 'numeric', 'min' => 0, 'max' => 50]]);
    tariffDraftRule($item, 'middle-only', '5', '50');

    $problems = app(CleaningV3CatalogDraftValidator::class)->review($version);
    expect($problems)->toContain(
        'TARIFF_START_GAP:restaurant.kitchen',
        'TARIFF_END_GAP:restaurant.kitchen',
    );

    tariffDraftRule($item, 'lower-bound', '0', '5');
    tariffDraftRule($item, 'upper-bound', '50', '51');

    $fixed = app(CleaningV3CatalogDraftValidator::class)->review($version);
    expect($fixed)->not->toContain(
        'TARIFF_START_GAP:restaurant.kitchen',
        'TARIFF_END_GAP:restaurant.kitchen',
        'TARIFF_GAP:restaurant.kitchen',
        'OVERLAPPING_TARIFFS:restaurant.kitchen',
    );

    // Structural coverage does not imply financial authorization.
    expect($fixed)->toContain('APPROVED_V3_PRICING_AND_FUNDING_PROOF_MISSING');
    expect($version->fresh()->status)->toBe('draft');
});

test('draft tariff edge checks do not invent form bounds when none were configured', function (): void {
    [$version, $item] = tariffDraftFixture();
    tariffDraftRule($item, 'unbounded-preview', '5', '50');

    $problems = app(CleaningV3CatalogDraftValidator::class)->review($version);
    expect($problems)->not->toContain(
        'TARIFF_START_GAP:restaurant.kitchen',
        'TARIFF_END_GAP:restaurant.kitchen',
    );
});
