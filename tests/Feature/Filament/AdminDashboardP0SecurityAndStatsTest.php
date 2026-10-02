<?php

declare(strict_types=1);

use App\Filament\Pages\RestaurantSectionHub;
use App\Filament\Pages\RestaurantStatsPage;
use App\Filament\Pages\SupermarketSectionHub;
use App\Filament\Pages\SupermarketStatsPage;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Services\ReportService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Filament::setCurrentPanel(filament()->getPanel('admin'));
});

function dashboardUserWithRole(string $roleName): User
{
    $role = Role::findOrCreate($roleName, 'web');
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user->fresh();
}

it('does not grant restaurant or supermarket dashboard access merely from panel access', function (): void {
    $support = dashboardUserWithRole('Customer Support');

    $this->actingAs($support);

    expect($support->canAccessPanel(filament()->getPanel('admin')))->toBeTrue();

    $this->get(RestaurantSectionHub::getUrl([], isAbsolute: false))->assertForbidden();
    $this->get(SupermarketSectionHub::getUrl([], isAbsolute: false))->assertForbidden();
    $this->get(OrderResource::getUrl('index', [], isAbsolute: false))->assertForbidden();
    $this->get(SmOrderResource::getUrl('index', [], isAbsolute: false))->assertForbidden();
});

it('grants restaurant order visibility independently from supermarket orders', function (): void {
    $support = dashboardUserWithRole('Customer Support');
    $permission = Permission::findByName('restaurant_orders.view', 'web');
    $support->givePermissionTo($permission);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs($support->fresh());

    $this->get(OrderResource::getUrl('index', [], isAbsolute: false))->assertSuccessful();
    $this->get(SmOrderResource::getUrl('index', [], isAbsolute: false))->assertForbidden();
});

it('calculates restaurant KPI totals from the full filtered dataset and uses weighted AOV', function (): void {
    $admin = dashboardUserWithRole('admin');
    $this->actingAs($admin);

    $restaurant = Restaurant::factory()->create();

    for ($i = 0; $i < 101; $i++) {
        $ordersCount = $i === 0 ? 100 : 1;
        $dailyRevenue = $i === 0 ? 1000 : 100;
        $amountPerOrder = $dailyRevenue / $ordersCount;

        Order::factory()->count($ordersCount)->create([
            'restaurant_id' => $restaurant->id,
            'status' => OrderStatus::Completed->value,
            'total_amount' => $amountPerOrder,
            'created_at' => now()->subDays($i),
            'updated_at' => now()->subDays($i),
        ]);
    }

    $component = Livewire::test(RestaurantStatsPage::class)
        ->set('dateRange', '120');

    $summary = $component->instance()->getViewData()['summary'];

    expect($summary['totalOrders'])->toBe(200)
        ->and($summary['totalRevenue'])->toBe(11000.0)
        ->and($summary['averageOrderValue'])->toBe(55.0)
        ->and($summary['trackedRestaurants'])->toBe(1);
});

it('calculates supermarket KPI totals from live completed orders across more than one hundred daily rows', function (): void {
    $admin = dashboardUserWithRole('admin');
    $this->actingAs($admin);

    $store = SmStore::factory()->create();

    for ($i = 0; $i < 101; $i++) {
        SmOrder::factory()->create([
            'store_id' => $store->id,
            'status' => SmOrderStatus::Completed->value,
            'total_amount' => 300,
            'created_at' => now()->subDays($i),
            'updated_at' => now()->subDays($i),
        ]);
    }

    SmOrder::factory()->create([
        'store_id' => $store->id,
        'status' => SmOrderStatus::Cancelled->value,
        'total_amount' => 99999,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $component = Livewire::test(SupermarketStatsPage::class)
        ->set('dateRange', '120');

    $summary = $component->instance()->getViewData()['summary'];

    expect($summary['totalOrders'])->toBe(101)
        ->and($summary['totalRevenue'])->toBe(30300.0)
        ->and($summary['averageOrderValue'])->toBe(300.0)
        ->and($summary['trackedStores'])->toBe(1);
});

it('includes current day orders in supermarket week and month dashboard totals', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-27 15:30:00'));

    $store = SmStore::factory()->create();

    SmOrder::factory()->create([
        'store_id' => $store->id,
        'total_amount' => 12500,
        'service_fee' => 500,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sales = app(ReportService::class)->getDashboardData()['sales_summary'];

    expect((float) $sales['today'])->toBe(12500.0)
        ->and((float) $sales['this_week'])->toBe(12500.0)
        ->and((float) $sales['this_month'])->toBe(12500.0);

    Carbon::setTestNow();
});
