<?php

declare(strict_types=1);

use App\Filament\Pages\RestaurantSectionHub;
use App\Filament\Pages\SupermarketSectionHub;
use App\Filament\Resources\MasterProductCategories\MasterProductCategoryResource;
use App\Filament\Resources\RestaurantInventoryItems\RestaurantInventoryItemResource;
use App\Filament\Resources\RestaurantOffers\RestaurantOfferResource;
use App\Filament\Resources\RestaurantOwners\RestaurantOwnerResource;
use App\Filament\Resources\RestaurantProducts\RestaurantProductResource;
use App\Filament\Resources\RestaurantPromoCodes\RestaurantPromoCodeResource;
use App\Filament\Resources\SmCategories\SmCategoryResource;
use App\Filament\Resources\SmStoreDocuments\SmStoreDocumentResource;
use App\Filament\Resources\SmStoreTrustLogs\SmStoreTrustLogResource;
use App\Filament\Resources\SupermarketOwners\SupermarketOwnerResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Resturants\Models\InventoryItem;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Models\Restaurant;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function dashboardIsolationUser(string $roleName): User
{
    $role = Role::findOrCreate($roleName, 'web');
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

it('provides read-only restaurant product inventory offer and coupon inspection', function (): void {
    $admin = dashboardIsolationUser('admin');
    $this->actingAs($admin);

    $restaurant = Restaurant::factory()->create(['name' => 'Catalog Restaurant']);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Catalog Burger',
        'price' => 12000,
        'stock_quantity' => 3,
        'low_stock_threshold' => 5,
    ]);

    $inventory = InventoryItem::query()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Burger Bun',
        'unit' => 'piece',
        'quantity' => 4,
        'minimum_limit' => 10,
        'unit_cost' => 1500,
    ]);
    $inventory->products()->attach($product->id, ['quantity_used' => 1]);

    $offer = Offer::factory()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Burger Offer',
        'discount_type' => 'percentage',
        'discount_value' => 15,
        'is_active' => true,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(2),
    ]);
    $offer->products()->attach($product->id);

    $promo = PromoCode::query()->create([
        'restaurant_id' => $restaurant->id,
        'code' => 'CATALOG15',
        'discount_type' => 'percentage',
        'discount_value' => 15,
        'min_order_amount' => 10000,
        'usage_limit' => 100,
        'usage_count' => 4,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(5),
        'is_active' => true,
    ]);

    $this->get(RestaurantProductResource::getUrl('view', ['record' => $product], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('Catalog Burger')
        ->assertSee('Burger Bun')
        ->assertSee('Burger Offer');

    $this->get(RestaurantInventoryItemResource::getUrl('view', ['record' => $inventory], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('Burger Bun')
        ->assertSee('Catalog Burger');

    $this->get(RestaurantOfferResource::getUrl('view', ['record' => $offer], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('Burger Offer')
        ->assertSee('Catalog Burger');

    $this->get(RestaurantPromoCodeResource::getUrl('view', ['record' => $promo], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('CATALOG15')
        ->assertSee('Catalog Restaurant');
});

it('keeps restaurant hub metrics and links inside the users permission scope', function (): void {
    $support = dashboardIsolationUser('Customer Support');
    $support->givePermissionTo(Permission::findOrCreate('restaurant_orders.view', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($support->fresh());

    $data = Livewire::test(RestaurantSectionHub::class)->instance()->getViewData();

    expect(collect($data['kpis'])->pluck('scope')->all())->toBe(['orders'])
        ->and($data['priorityRestaurantRows'])->toBe([])
        ->and($data['priorityDisputeRows'])->toBe([])
        ->and($data['priorityProductRows'])->toBe([])
        ->and($data['checklist'])->toBe([]);

    $quickLabels = collect($data['quickActions'])->pluck('label');
    expect($quickLabels)->toContain(__('restaurant_admin.hub.quick_actions.orders'))
        ->and($quickLabels)->toContain(__('restaurant_admin.hub.quick_actions.stats'))
        ->and($quickLabels)->not->toContain('منتجات المطاعم')
        ->and($quickLabels)->not->toContain(__('restaurant_admin.hub.quick_actions.disputes'));
});

it('keeps supermarket hub data inside the users permission scope', function (): void {
    $support = dashboardIsolationUser('Customer Support');
    $support->givePermissionTo(Permission::findOrCreate('supermarket_orders.view', 'web'));
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($support->fresh());

    $data = Livewire::test(SupermarketSectionHub::class)->instance()->getViewData();

    $metricLabels = collect($data['overviewKpis'])->pluck('label');
    expect($metricLabels)->toHaveCount(1)
        ->and($metricLabels)->toContain(__('supermarket_admin.metrics.total_orders'))
        ->and($metricLabels)->not->toContain(__('supermarket_admin.metrics.total_stores'))
        ->and($metricLabels)->not->toContain(__('supermarket_admin.metrics.open_disputes'))
        ->and($metricLabels)->not->toContain(__('supermarket_admin.metrics.low_stock_products'));

    $workflowTitles = collect($data['workflowSections'])->pluck('title');
    expect($workflowTitles)->toContain(__('supermarket_admin.flow.operations.title'))
        ->and($workflowTitles)->not->toContain(__('supermarket_admin.flow.governance.title'))
        ->and($workflowTitles)->not->toContain(__('supermarket_admin.flow.catalog.title'));

    expect(collect($data['attentionGroups'])->pluck('key')->all())->toBe(['fulfillment']);
});

it('blocks direct secondary domain resources without matching permissions', function (): void {
    $support = dashboardIsolationUser('Customer Support');
    $this->actingAs($support);

    $this->get(RestaurantProductResource::getUrl('index', [], isAbsolute: false))->assertForbidden();
    $this->get(RestaurantOwnerResource::getUrl('index', [], isAbsolute: false))->assertForbidden();
    $this->get(SmCategoryResource::getUrl('index', [], isAbsolute: false))->assertForbidden();
    $this->get(MasterProductCategoryResource::getUrl('index', [], isAbsolute: false))->assertForbidden();
    $this->get(SmStoreDocumentResource::getUrl('index', [], isAbsolute: false))->assertForbidden();
    $this->get(SmStoreTrustLogResource::getUrl('index', [], isAbsolute: false))->assertForbidden();
    $this->get(SupermarketOwnerResource::getUrl('index', [], isAbsolute: false))->assertForbidden();
});
