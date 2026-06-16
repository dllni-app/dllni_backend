<?php

declare(strict_types=1);

use App\Filament\Company\Pages\CompanyDashboard;
use App\Filament\Company\Pages\DeliveryFinancialPage;
use App\Filament\Company\Pages\DeliveryNotificationsPage;
use App\Filament\Company\Pages\DeliveryReportsPage;
use App\Filament\Company\Resources\DeliveryDisputes\DeliveryDisputeResource;
use App\Filament\Company\Resources\DeliveryDrivers\DeliveryDriverResource;
use App\Filament\Company\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Company\Resources\DeliveryOrders\Pages\CreateDeliveryOrder;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Modules\Delivery\Database\Seeders\DeliveryPermissionsSeeder;
use Modules\Delivery\Jobs\DispatchDeliveryOrderJob;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Services\DeliveryOrderService;
use Modules\Delivery\Services\DriverManagementService;
use Modules\Delivery\Services\FinancialLedgerService;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(DeliveryPermissionsSeeder::class);
});

function createCompanyAdminUser(DeliveryCompany $company): User
{
    $user = User::factory()->create([
        'email' => 'company-admin-'.uniqid().'@example.com',
    ]);

    $company->update(['owner_user_id' => $user->id]);
    $user->assignRole('delivery_company_admin');

    return $user->fresh();
}

it('denies company panel access to users without delivery company roles', function (): void {
    $user = User::factory()->create();

    expect($user->canAccessPanel(filament()->getPanel('company')))->toBeFalse();
});

it('allows delivery company admin to access the company panel and order pages', function (): void {
    $company = DeliveryCompany::factory()->create();
    $admin = createCompanyAdminUser($company);

    $this->actingAs($admin);

    expect($admin->canAccessPanel(filament()->getPanel('company')))->toBeTrue();

    $this->get(CompanyDashboard::getUrl(panel: 'company'))
        ->assertSuccessful();

    $this->get(DeliveryOrderResource::getUrl('index', panel: 'company'))
        ->assertSuccessful();

    $this->get(DeliveryOrderResource::getUrl('create', panel: 'company'))
        ->assertSuccessful();

    $this->get(DeliveryDriverResource::getUrl('index', panel: 'company'))
        ->assertSuccessful();

    $this->get(DeliveryFinancialPage::getUrl(panel: 'company'))
        ->assertSuccessful();

    $this->get(DeliveryNotificationsPage::getUrl(panel: 'company'))
        ->assertSuccessful();

    $this->get(DeliveryReportsPage::getUrl(panel: 'company'))
        ->assertSuccessful();

    $this->get(DeliveryDisputeResource::getUrl('index', panel: 'company'))
        ->assertSuccessful();
});

it('scopes order listing to the authenticated company', function (): void {
    $companyA = DeliveryCompany::factory()->create(['name' => 'Company A']);
    $companyB = DeliveryCompany::factory()->create(['name' => 'Company B']);
    $adminA = createCompanyAdminUser($companyA);

    DeliveryOrder::factory()->create([
        'company_id' => $companyA->id,
        'order_number' => 'DEL-SCOPED-A-001',
        'customer_name' => 'Customer A',
        'pickup_address' => 'A pickup',
        'dropoff_address' => 'A dropoff',
        'pickup_latitude' => 33.51,
        'pickup_longitude' => 36.27,
        'dropoff_latitude' => 33.52,
        'dropoff_longitude' => 36.28,
        'distance_km' => 1.5,
        'delivery_fee' => 6000,
        'currency' => 'SYP',
        'status' => 'new',
    ]);

    DeliveryOrder::factory()->create([
        'company_id' => $companyB->id,
        'order_number' => 'DEL-SCOPED-B-001',
        'customer_name' => 'Customer B',
        'pickup_address' => 'B pickup',
        'dropoff_address' => 'B dropoff',
        'pickup_latitude' => 33.51,
        'pickup_longitude' => 36.27,
        'dropoff_latitude' => 33.52,
        'dropoff_longitude' => 36.28,
        'distance_km' => 2.0,
        'delivery_fee' => 7000,
        'currency' => 'SYP',
        'status' => 'new',
    ]);

    $this->actingAs($adminA);

    $visibleOrderNumbers = DeliveryOrderResource::getEloquentQuery()
        ->pluck('order_number')
        ->all();

    expect($visibleOrderNumbers)->toContain('DEL-SCOPED-A-001')
        ->and($visibleOrderNumbers)->not->toContain('DEL-SCOPED-B-001');
});

it('creates an order through the company create page using DeliveryOrderService', function (): void {
    Queue::fake();

    $company = DeliveryCompany::factory()->create();
    $admin = createCompanyAdminUser($company);

    $this->actingAs($admin);
    Filament::setCurrentPanel('company');

    Livewire::test(CreateDeliveryOrder::class)
        ->fillForm([
            'customer_name' => 'Filament Customer',
            'customer_phone' => '+963900000099',
            'pickup_address' => 'Pickup',
            'pickup_latitude' => 33.5138,
            'pickup_longitude' => 36.2765,
            'dropoff_address' => 'Dropoff',
            'dropoff_latitude' => 33.5200,
            'dropoff_longitude' => 36.2900,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $order = DeliveryOrder::query()
        ->where('company_id', $company->id)
        ->where('customer_name', 'Filament Customer')
        ->first();

    expect($order)->not->toBeNull()
        ->and($order->status)->toBe('dispatching');

    Queue::assertPushed(DispatchDeliveryOrderJob::class);
});

it('scopes drivers to the authenticated company', function (): void {
    $companyA = DeliveryCompany::factory()->create();
    $companyB = DeliveryCompany::factory()->create();
    $adminA = createCompanyAdminUser($companyA);

    DeliveryDriver::factory()->create(['company_id' => $companyA->id, 'first_name' => 'Driver A']);
    DeliveryDriver::factory()->create(['company_id' => $companyB->id, 'first_name' => 'Driver B']);

    $this->actingAs($adminA);

    $names = DeliveryDriverResource::getEloquentQuery()->pluck('first_name')->all();

    expect($names)->toContain('Driver A')
        ->and($names)->not->toContain('Driver B');
});

it('suspends and unsuspends a driver through DriverManagementService', function (): void {
    $company = DeliveryCompany::factory()->create();
    $driver = DeliveryDriver::factory()->create(['company_id' => $company->id]);

    $service = app(DriverManagementService::class);
    $suspended = $service->suspend($driver, 'manual review');

    expect($suspended->is_suspended)->toBeTrue()
        ->and($suspended->availability_status)->toBe('offline');

    $unsuspended = $service->unsuspend($suspended);

    expect($unsuspended->is_suspended)->toBeFalse();
});

it('loads company financial account on the financial page', function (): void {
    $company = DeliveryCompany::factory()->create(['financial_limit' => 50000]);
    $admin = createCompanyAdminUser($company);

    $account = app(FinancialLedgerService::class)->accountForCompany($company);

    $this->actingAs($admin);

    $this->get(DeliveryFinancialPage::getUrl(panel: 'company'))
        ->assertSuccessful()
        ->assertSee((string) $account->currency);
});

it('cancels a stopped order through DeliveryOrderService', function (): void {
    $company = DeliveryCompany::factory()->create();
    $order = app(DeliveryOrderService::class)->create($company, [
        'customerName' => 'Cancel Me',
        'pickupAddress' => 'Pickup',
        'pickupLatitude' => 33.5138,
        'pickupLongitude' => 36.2765,
        'dropoffAddress' => 'Dropoff',
        'dropoffLatitude' => 33.5200,
        'dropoffLongitude' => 36.2900,
    ]);

    $order->forceFill([
        'status' => 'stopped',
        'stopped_at' => now(),
        'stop_reason' => 'No drivers',
    ])->save();

    $cancelled = app(DeliveryOrderService::class)->cancel($order, 'Customer requested cancellation');

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->cancel_reason)->toBe('Customer requested cancellation')
        ->and($cancelled->cancelled_at)->not->toBeNull();
});
