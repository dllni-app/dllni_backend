<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CatalogReviewManifestService;

uses(RefreshDatabase::class);

function reviewCatalogFixture(): array
{
    $source = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'review_manifest_sample',
        'booking_value' => 'review_manifest_sample',
        'title' => 'منشأة تجريبية',
        'is_active' => true,
    ]);
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'Review-only catalog']);
    $item = $version->items()->create([
        'item_key' => 'review.kitchen',
        'source_type' => 'property',
        'source_id' => $source->id,
        'label_ar' => 'مطبخ',
        'measurement_mode' => 'sqm',
        'input_rules' => ['step' => 1, 'min' => 0],
        'required' => true,
        'is_enabled' => true,
    ]);
    $rule = $item->tariffRules()->create([
        'rule_key' => 'regular',
        'cleaning_mode' => 'regular',
        'scope_key' => 'whole',
        'quantity_min' => '0',
        'quantity_max' => '100',
        'unit_price' => '150000.00',
        'unit_effort_minutes' => '20.00',
        'is_enabled' => true,
    ]);

    return [$version, $item, $rule];
}

test('V3 review fingerprints include prices and are deterministic without approving or publishing', function (): void {
    [$version, $item, $rule] = reviewCatalogFixture();
    $service = app(CleaningV3CatalogReviewManifestService::class);
    $before = $service->inspect($version);
    $again = $service->inspect($version->fresh());

    expect($before)->toBe($again)
        ->and($before['itemCount'])->toBe(1)
        ->and($before['tariffRuleCount'])->toBe(1)
        ->and($before['fingerprint'])->toMatch('/^[a-f0-9]{64}$/')
        ->and($before['readyForPublication'])->toBeFalse()
        ->and($before['issueCodes'])->toContain('APPROVED_V3_PRICING_AND_FUNDING_PROOF_MISSING');

    $item->update(['input_rules' => ['min' => 0, 'step' => 1]]);
    expect($service->inspect($version)['fingerprint'])->toBe($before['fingerprint']);

    $rule->update(['unit_price' => '160000.00']);
    $priced = $service->inspect($version);
    expect($priced['fingerprint'])->not->toBe($before['fingerprint']);

    $item->update(['label_ar' => 'مطبخ كبير']);
    expect($service->inspect($version)['fingerprint'])->not->toBe($priced['fingerprint'])
        ->and($version->fresh()->status)->toBe('draft')
        ->and($version->fresh()->published_digest)->toBeNull()
        ->and(DB::table('cleaning_booking_quotes')->count())->toBe(0);
});

test('only authorized draft administrators can run the nonpublishing Filament manifest action', function (): void {
    [$version] = reviewCatalogFixture();
    app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = App\Models\User::factory()->create();
    $admin->assignRole(Spatie\Permission\Models\Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin);

    Livewire\Livewire::test(
        App\Filament\Resources\CleaningV3CatalogVersions\Pages\EditCleaningV3CatalogVersion::class,
        ['record' => $version->getRouteKey()],
    )->callAction('reviewDraftManifest')->assertHasNoErrors();

    expect($version->fresh()->status)->toBe('draft')
        ->and($version->fresh()->published_at)->toBeNull()
        ->and($version->fresh()->published_digest)->toBeNull();
});

test('V3 review manifest refuses historical published versions', function (): void {
    [$version] = reviewCatalogFixture();
    DB::table('cleaning_v3_catalog_versions')->where('id', $version->id)
        ->update(['status' => CleaningV3CatalogVersion::PUBLISHED]);
    expect(fn () => app(CleaningV3CatalogReviewManifestService::class)->inspect($version->fresh()))
        ->toThrow(DomainException::class, 'V3_REVIEW_REQUIRES_DRAFT');
});
