<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningV3CatalogVersions\CleaningV3CatalogVersionResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CatalogDraftValidator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('creates an isolated draft without changing V2 catalog rows or exposing a publish action', function (): void {
    $home = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'v3_qa_home',
        'booking_value' => 'v3_qa_home',
        'title' => 'منزل لاختبار المسودة',
        'is_active' => true,
    ]);

    $before = CleaningHomeType::query()->count();
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'Draft QA 1']);
    expect($version->version_key)->toBeString()
        ->and($version->status)->toBe('draft')
        ->and($version->published_at)->toBeNull()
        ->and($version->published_digest)->toBeNull();

    $item = $version->items()->create([
        'item_key' => 'property.sample',
        'source_type' => 'property',
        'source_id' => $home->id,
        'label_ar' => 'عينّة عقار',
        'measurement_mode' => 'sqm',
        'is_enabled' => true,
    ]);
    $size = $item->presets()->create([
        'preset_key' => 'medium',
        'label_ar' => 'متوسط',
        'minimum_value' => 12,
        'maximum_value' => 30,
    ]);

    expect($version->items()->count())->toBe(1)
        ->and($item->presets()->count())->toBe(1)
        ->and((string) $size->maximum_value)->toBe('30.00')
        ->and(CleaningHomeType::query()->count())->toBe($before)
        ->and(app(CleaningV3CatalogDraftValidator::class)->review($version))
        ->toContain('APPROVED_CATALOG_COVERAGE_MATRIX_MISSING', 'APPROVED_V3_PRICING_AND_FUNDING_PROOF_MISSING');

    expect(fn () => app(CleaningV3CatalogDraftValidator::class)->publish($version))
        ->toThrow(DomainException::class);
    expect($version->fresh()->status)->toBe('draft');
});

it('rejects invalid source IDs, invalid measurement modes and invalid size ranges', function (): void {
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'Draft QA 2']);

    expect(fn () => $version->items()->create([
        'item_key' => 'item.bad',
        'source_type' => 'room',
        'source_id' => 999999999,
        'label_ar' => 'غير موجود',
        'measurement_mode' => 'sqm',
    ]))->toThrow(ValidationException::class);

    $home = CleaningHomeType::query()->create([
        'section' => CleaningHomeType::SECTION_PROPERTY,
        'code' => 'v3_qa_validation_home',
        'title' => 'اختبار',
        'is_active' => true,
    ]);
    expect(fn () => $version->items()->create([
        'item_key' => 'item.unknown',
        'source_type' => 'property',
        'source_id' => $home->id,
        'label_ar' => 'اختبار',
        'measurement_mode' => 'unrecognized_unit',
    ]))->toThrow(ValidationException::class);

    $item = $version->items()->create([
        'item_key' => 'property.valid',
        'source_type' => 'property',
        'source_id' => $home->id,
        'label_ar' => 'صالح',
        'measurement_mode' => 'sqm',
    ]);

    expect(fn () => $item->presets()->create([
        'preset_key' => 'bad',
        'label_ar' => 'خطأ',
        'minimum_value' => 20,
        'maximum_value' => 10,
    ]))->toThrow(ValidationException::class);

    $home->update(['is_active' => false]);
    expect(app(CleaningV3CatalogDraftValidator::class)->review($version))->toContain('INVALID_SOURCE:property.valid');
});

it('keeps published catalog rows immutable and refuses bypassing the draft-only state', function (): void {
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'Locked Catalog']);

    expect(fn () => $version->forceFill(['status' => CleaningV3CatalogVersion::PUBLISHED])->save())
        ->toThrow(LogicException::class);

    Illuminate\Support\Facades\DB::table('cleaning_v3_catalog_versions')
        ->where('id', $version->id)
        ->update(['status' => CleaningV3CatalogVersion::PUBLISHED]);

    $locked = $version->fresh();
    expect(fn () => $locked->update(['title' => 'tampered']))->toThrow(LogicException::class)
        ->and(fn () => $locked->delete())->toThrow(LogicException::class);

    expect(CleaningV3CatalogVersionResource::canDelete($locked))->toBeFalse();
});

it('restricts Filament draft catalog access and editing to dedicated permissions', function (): void {
    $version = CleaningV3CatalogVersion::query()->create(['title' => 'Permissions QA']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $support = User::factory()->create();
    $role = Role::findOrCreate('v3_catalog_support_test', 'web');
    $support->assignRole($role);
    $this->actingAs($support);
    expect(CleaningV3CatalogVersionResource::canViewAny())->toBeFalse()
        ->and(CleaningV3CatalogVersionResource::canCreate())->toBeFalse()
        ->and(CleaningV3CatalogVersionResource::canEdit($version))->toBeFalse();

    $support->givePermissionTo(Permission::findOrCreate('cleaning-v3-catalog.view', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(CleaningV3CatalogVersionResource::canViewAny())->toBeTrue()
        ->and(CleaningV3CatalogVersionResource::canEdit($version))->toBeFalse();

    $support->givePermissionTo(Permission::findOrCreate('cleaning-v3-catalog.update', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(CleaningV3CatalogVersionResource::canEdit($version))->toBeTrue()
        ->and(CleaningV3CatalogVersionResource::canCreate())->toBeFalse();
});
