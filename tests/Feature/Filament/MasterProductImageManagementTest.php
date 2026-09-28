<?php

declare(strict_types=1);

use App\Filament\Resources\MasterProducts\Pages\CreateMasterProduct;
use App\Filament\Resources\MasterProducts\Pages\EditMasterProduct;
use App\Models\MasterProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);
    Storage::fake((string) config('media-library.disk_name', 'public'));
});
it('creates replaces and removes the master product image through Filament', function (): void {
    Livewire::test(CreateMasterProduct::class)
        ->fillForm([
            'name' => 'Master Product Image QA',
            'barcode' => 'MP-IMAGE-QA-001',
            'unit' => 'piece',
            'brand' => 'QA Brand',
            'is_active' => true,
            'image_upload' => UploadedFile::fake()->image('master-one.jpg', 400, 400),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $product = MasterProduct::query()
        ->where('barcode', 'MP-IMAGE-QA-001')
        ->firstOrFail();

    $firstMedia = $product->getFirstMedia(MasterProduct::IMAGE_COLLECTION);

    expect($firstMedia)->not->toBeNull()
        ->and($product->getMedia(MasterProduct::IMAGE_COLLECTION))->toHaveCount(1);

    Livewire::test(EditMasterProduct::class, ['record' => $product->getRouteKey()])
        ->fillForm([
            'image_upload' => UploadedFile::fake()->image('master-two.jpg', 500, 500),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $product->refresh();
    $replacement = $product->getFirstMedia(MasterProduct::IMAGE_COLLECTION);

    expect($product->getMedia(MasterProduct::IMAGE_COLLECTION))->toHaveCount(1)
        ->and($replacement)->not->toBeNull()
        ->and($replacement?->getKey())->not->toBe($firstMedia?->getKey());

    Livewire::test(EditMasterProduct::class, ['record' => $product->getRouteKey()])
        ->assertActionExists('remove_image')
        ->callAction('remove_image');

    expect($product->fresh()->getMedia(MasterProduct::IMAGE_COLLECTION))->toHaveCount(0);
});
