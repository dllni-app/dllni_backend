<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningV3CatalogVersions\CleaningV3CatalogVersionResource;
use App\Filament\Resources\CleaningV3CatalogVersions\Pages\CreateCleaningV3CatalogVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('renders the authorized V3 draft catalog pages and persists a draft through Filament', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin);

    $index = CleaningV3CatalogVersionResource::getUrl('index', isAbsolute: false);
    $create = CleaningV3CatalogVersionResource::getUrl('create', isAbsolute: false);

    $this->get($index)->assertOk()->assertSee('مسودات كتالوج');
    $this->get($create)->assertOk()->assertSee('اسم المسودة');

    Livewire::test(CreateCleaningV3CatalogVersion::class)
        ->fillForm(['title' => 'QA admin draft', 'notes' => 'Not approved, not published'])
        ->call('create')
        ->assertHasNoFormErrors();

    $draft = CleaningV3CatalogVersion::query()->where('title', 'QA admin draft')->firstOrFail();
    expect($draft->status)->toBe('draft')
        ->and($draft->created_by)->toBe($admin->id)
        ->and($draft->published_at)->toBeNull()
        ->and($draft->published_digest)->toBeNull();

    $this->get(CleaningV3CatalogVersionResource::getUrl('edit', ['record' => $draft], isAbsolute: false))
        ->assertOk()->assertSee('QA admin draft');
});

it('blocks unrelated users from V3 catalog admin pages', function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $user = User::factory()->create();
    $role = Role::findOrCreate('v3_ui_no_access', 'web');
    $user->assignRole($role);
    $this->actingAs($user);

    expect(CleaningV3CatalogVersionResource::canViewAny())->toBeFalse();

    $page = $this->get(CleaningV3CatalogVersionResource::getUrl('index', isAbsolute: false));
    expect($page->status())->not->toBe(200);

    $permission = Permission::findOrCreate('cleaning-v3-catalog.view', 'web');
    $user->givePermissionTo($permission);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    expect(CleaningV3CatalogVersionResource::canCreate())->toBeFalse();
});
