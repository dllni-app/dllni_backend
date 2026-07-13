<?php

declare(strict_types=1);

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Filament\Pages\CleaningOverview;
use App\Filament\Pages\GeographicCoverage;
use App\Filament\Pages\RestaurantSectionHub;
use App\Filament\Pages\SupermarketSectionHub;
use App\Filament\Pages\SupermarketStatsPage;
use App\Filament\Resources\CleaningNeighborhoods\CleaningNeighborhoodResource;
use App\Filament\Resources\CleaningNeighborhoods\Pages\CreateCleaningNeighborhood;
use App\Filament\Resources\CleaningNeighborhoods\Pages\EditCleaningNeighborhood;
use App\Filament\Resources\MasterProducts\MasterProductResource;
use App\Filament\Resources\SmCategories\SmCategoryResource;
use App\Filament\Resources\SmOrderDisputes\SmOrderDisputeResource;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Filament\Resources\SmStores\SmStoreResource;
use App\Models\SystemAlert;
use App\Models\User;
use Database\Factories\OrderFactory;
use Livewire\Livewire;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Resturants\Models\Order;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create([
        'email' => 'hub-pages-test@example.com',
    ]);
    $adminUser->assignRole('admin');

    expect($adminUser->fresh()->hasRole('admin', $guardName))->toBeTrue();

    $this->actingAs($adminUser);
});

it('allows an admin to load the supermarket section hub', function (): void {
    $this->get(SupermarketSectionHub::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});

it('allows an admin to load the supermarket statistics page', function (): void {
    $this->get(SupermarketStatsPage::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});

it('allows an admin to load supermarket store order and dispute list pages', function (): void {
    $this->get(SmStoreResource::getUrl('index', [], isAbsolute: false))
        ->assertSuccessful();
    $this->get(SmOrderResource::getUrl('index', [], isAbsolute: false))
        ->assertSuccessful();
    $this->get(SmOrderDisputeResource::getUrl('index', [], isAbsolute: false))
        ->assertSuccessful();
});

it('allows an admin to load supermarket category and master product list pages', function (): void {
    $this->get(SmCategoryResource::getUrl('index', [], isAbsolute: false))
        ->assertSuccessful();
    $this->get(MasterProductResource::getUrl('index', [], isAbsolute: false))
        ->assertSuccessful();
});

it('allows an admin to load the restaurant section hub', function (): void {
    $this->get(RestaurantSectionHub::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});

it('allows an admin to load the cleaning overview command center', function (): void {
    $this->get(CleaningOverview::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});

it('injects the latin digits script into the admin panel head', function (): void {
    $this->get(CleaningOverview::getUrl([], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee("numberingSystem = 'latn'", escape: false);
});

it('allows an admin to load cleaning neighborhoods and geographic coverage pages', function (): void {
    $this->get(CleaningNeighborhoodResource::getUrl('index', [], isAbsolute: false))
        ->assertSuccessful();
    $this->get(GeographicCoverage::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});

it('keeps geographic coverage read-only and links to canonical neighborhood management', function (): void {
    $neighborhood = CleaningNeighborhood::factory()->create([
        'name_ar' => 'Coverage test neighborhood',
    ]);

    Livewire::test(GeographicCoverage::class)
        ->assertCanSeeTableRecords([$neighborhood])
        ->assertTableActionDoesNotExist('edit', record: $neighborhood)
        ->assertTableActionDoesNotExist('toggle_active', record: $neighborhood)
        ->assertTableBulkActionDoesNotExist('activate')
        ->assertTableBulkActionDoesNotExist('deactivate')
        ->assertSee(CleaningNeighborhoodResource::getUrl('index', [], isAbsolute: false), escape: false)
        ->assertDontSee(CleaningNeighborhoodResource::getUrl('create', [], isAbsolute: false), escape: false);
});

it('allows an admin to load and submit the cleaning neighborhood create form', function (): void {
    $this->get(CleaningNeighborhoodResource::getUrl('create', [], isAbsolute: false))
        ->assertSuccessful();

    Livewire::test(CreateCleaningNeighborhood::class)
        ->fillForm([
            'name_ar' => 'حي الاختبار',
            'city_name' => 'حلب',
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('cleaning_neighborhoods', [
        'name_ar' => 'حي الاختبار',
    ]);
});

it('rejects a duplicate neighborhood name on create', function (): void {
    CleaningNeighborhood::factory()->create(['name_ar' => 'حي مكرر']);

    Livewire::test(CreateCleaningNeighborhood::class)
        ->fillForm([
            'name_ar' => 'حي مكرر',
            'city_name' => 'حلب',
            'sort_order' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['name_ar']);
});

it('allows an admin to save the neighborhood edit form without a self unique conflict', function (): void {
    $neighborhood = CleaningNeighborhood::factory()->create(['name_ar' => 'حي قائم']);

    Livewire::test(EditCleaningNeighborhood::class, ['record' => $neighborhood->getRouteKey()])
        ->fillForm([
            'name_ar' => 'حي قائم',
            'sort_order' => 5,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('cleaning_neighborhoods', [
        'id' => $neighborhood->id,
        'sort_order' => 5,
    ]);
});

it('allows an admin to load the cleaning overview when a system alert targets a restaurant order', function (): void {
    $order = OrderFactory::new()->create();

    SystemAlert::query()->create([
        'booking_id' => $order->id,
        'booking_type' => Order::class,
        'alert_type' => AlertType::DelayedRating,
        'severity' => AlertSeverity::Medium,
        'status' => SystemAlertStatus::New,
        'payload' => [],
    ]);

    $this->get(CleaningOverview::getUrl([], isAbsolute: false))
        ->assertSuccessful();
});
