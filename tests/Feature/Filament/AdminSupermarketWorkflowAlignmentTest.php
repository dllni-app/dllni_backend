<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Filament\Pages\SupermarketSectionHub;
use App\Filament\Resources\SmCategories\SmCategoryResource;
use App\Filament\Resources\SmCoupons\SmCouponResource;
use App\Filament\Resources\SmOffers\SmOfferResource;
use App\Filament\Resources\SmProducts\Pages\ViewSmProduct;
use App\Filament\Resources\SmProducts\SmProductResource;
use App\Filament\Resources\SmStores\RelationManagers\CommissionRulesRelationManager;
use App\Filament\Resources\SmStores\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\SmStores\SmStoreResource;
use App\Filament\Resources\SupermarketOwners\SupermarketOwnerResource;
use App\Models\User;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreStaffFactory;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Models\SmStoreDocument;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    Filament::setCurrentPanel(filament()->getPanel('admin'));

    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);

    $this->actingAs($admin);
});

it('keeps store-owned catalog read only while exposing explicit audited moderation', function (): void {
    $store = SmStore::factory()->create();
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'is_available' => true,
    ]);

    expect(SmProductResource::canEdit($product))->toBeFalse()
        ->and(SmProductResource::canModerate())->toBeTrue()
        ->and(SmCategoryResource::canCreate())->toBeFalse()
        ->and(SmCategoryResource::canEdit(new Modules\Supermarket\Models\SmCategory()))->toBeFalse()
        ->and(SmOfferResource::canEdit(new Modules\Supermarket\Models\SmOffer()))->toBeFalse()
        ->and(SmOfferResource::canModerate())->toBeTrue()
        ->and(SmCouponResource::canEdit(new Modules\Supermarket\Models\SmCoupon()))->toBeFalse()
        ->and(SmCouponResource::canModerate())->toBeTrue();

    Livewire::test(ViewSmProduct::class, ['record' => $product->getRouteKey()])
        ->assertActionExists('moderate_availability')
        ->callAction('moderate_availability', data: ['reason' => 'Policy moderation test'])
        ->assertHasNoActionErrors();

    expect((bool) $product->fresh()->is_available)->toBeFalse()
        ->and(Activity::query()
            ->where('log_name', 'supermarket_admin_overrides')
            ->where('subject_id', $product->id)
            ->where('description', 'supermarket_product_availability_override')
            ->exists())->toBeTrue();
});

it('connects store documents and commission rules directly to the store administration page', function (): void {
    $store = SmStore::factory()->create();

    expect(SmStoreResource::getRelations())
        ->toContain(DocumentsRelationManager::class)
        ->toContain(CommissionRulesRelationManager::class);

    $this->get(SmStoreResource::getUrl('view', ['record' => $store], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee($store->name);
});

it('surfaces expiring supermarket documents in the compliance attention queues', function (): void {
    $store = SmStore::factory()->create(['name' => 'Expiry Queue Store']);

    SmStoreDocument::query()->create([
        'store_id' => $store->id,
        'document_type' => 'commercial_registration',
        'file_path' => 'documents/test.pdf',
        'verification_status' => 'approved',
        'verified_at' => now()->subDay(),
        'verified_by_user_id' => auth()->id(),
        'expires_at' => now()->addDays(10),
    ]);

    $data = Livewire::test(SupermarketSectionHub::class)->instance()->getViewData();

    $compliance = collect($data['attentionGroups'])->firstWhere('key', 'compliance');
    $queue = collect($compliance['queues'] ?? [])->firstWhere('title', 'وثائق منتهية أو تنتهي خلال 30 يوم');

    expect($queue)->not->toBeNull()
        ->and($queue['count'])->toBe(1)
        ->and(collect($queue['items'])->pluck('label')->implode(' '))->toContain('Expiry Queue Store');
});

it('keeps supermarket employees out of the supermarket owners resource', function (): void {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    $store = SmStore::factory()->create(['owner_user_id' => $owner->id]);

    $employee = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    SmStoreStaffFactory::new()->create([
        'store_id' => $store->id,
        'user_id' => $employee->id,
        'is_active' => true,
    ]);

    $ids = SupermarketOwnerResource::getEloquentQuery()->pluck('id');

    expect($ids)->toContain($owner->id)
        ->and($ids)->not->toContain($employee->id);
});
