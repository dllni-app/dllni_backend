<?php

declare(strict_types=1);

use App\Enums\EmergencyType;
use App\Enums\SupportCaseKind;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseReporterRole;
use App\Enums\SupportCaseStatus;
use App\Filament\Resources\SupportCases\SupportCaseResource;
use App\Models\SupportCase;
use App\Models\User;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmStore;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $adminRole = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $this->actingAs($admin);
});

it('renders restaurant and supermarket SOS with the correct merchant context', function (): void {
    $customer = User::factory()->create([
        'name' => 'عميل الطلب',
        'phone' => '+963944111111',
    ]);
    $restaurant = Restaurant::factory()->create([
        'name' => 'مطعم الاختبار',
        'phone' => '+963944222222',
    ]);
    $restaurantOrder = Order::factory()->create([
        'user_id' => $customer->id,
        'restaurant_id' => $restaurant->id,
        'status' => 'accepted',
        'order_number' => 'REST-SOS-100',
    ]);
    $restaurantCase = SupportCase::query()->create([
        'case_number' => 'SOS-REST-100',
        'kind' => SupportCaseKind::Emergency,
        'priority' => SupportCasePriority::Critical,
        'booking_id' => $restaurantOrder->id,
        'booking_type' => Order::class,
        'reporter_id' => $customer->id,
        'reporter_role' => SupportCaseReporterRole::Customer,
        'category' => EmergencyType::SafetyThreat->value,
        'description' => 'Restaurant emergency.',
        'status' => SupportCaseStatus::New,
    ]);

    $this->get(SupportCaseResource::getUrl('view', ['record' => $restaurantCase], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('المطاعم')
        ->assertSee('REST-SOS-100')
        ->assertSee('مطعم الاختبار')
        ->assertSee('+963944222222');

    $store = SmStore::factory()->create([
        'name' => 'سوبرماركت الاختبار',
        'phone' => '+963944333333',
    ]);
    $supermarketOrder = SmOrder::factory()->create([
        'customer_id' => $customer->id,
        'store_id' => $store->id,
        'status' => 'accepted',
        'order_number' => 'SM-SOS-200',
    ]);
    $supermarketCase = SupportCase::query()->create([
        'case_number' => 'SOS-SM-200',
        'kind' => SupportCaseKind::Emergency,
        'priority' => SupportCasePriority::Critical,
        'booking_id' => $supermarketOrder->id,
        'booking_type' => SmOrder::class,
        'reporter_id' => $customer->id,
        'reporter_role' => SupportCaseReporterRole::Customer,
        'category' => EmergencyType::MedicalEmergency->value,
        'description' => 'Supermarket emergency.',
        'status' => SupportCaseStatus::New,
    ]);

    $this->get(SupportCaseResource::getUrl('view', ['record' => $supermarketCase], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('السوبرماركت')
        ->assertSee('SM-SOS-200')
        ->assertSee('سوبرماركت الاختبار')
        ->assertSee('+963944333333');
});

it('renders delivery SOS with driver context', function (): void {
    $customer = User::factory()->create([
        'name' => 'عميل التوصيل',
        'phone' => '+963955111111',
    ]);
    $driverUser = User::factory()->create([
        'name' => 'مندوب الاختبار',
        'phone' => '+963955222222',
    ]);
    $driver = DeliveryDriver::factory()->create([
        'user_id' => $driverUser->id,
        'first_name' => 'مندوب الاختبار',
    ]);
    $deliveryOrder = DeliveryOrder::factory()->create([
        'driver_id' => $driver->id,
        'created_by_user_id' => $customer->id,
        'status' => 'accepted',
        'order_number' => 'DEL-SOS-300',
    ]);
    $supportCase = SupportCase::query()->create([
        'case_number' => 'SOS-DEL-300',
        'kind' => SupportCaseKind::Emergency,
        'priority' => SupportCasePriority::Critical,
        'booking_id' => $deliveryOrder->id,
        'booking_type' => 'delivery_order',
        'reporter_id' => $customer->id,
        'reporter_role' => SupportCaseReporterRole::Customer,
        'category' => EmergencyType::SevereConflict->value,
        'description' => 'Delivery emergency.',
        'status' => SupportCaseStatus::New,
    ]);

    $this->get(SupportCaseResource::getUrl('view', ['record' => $supportCase], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('التوصيل')
        ->assertSee('DEL-SOS-300')
        ->assertSee('مندوب الاختبار')
        ->assertSee('+963955222222');
});
