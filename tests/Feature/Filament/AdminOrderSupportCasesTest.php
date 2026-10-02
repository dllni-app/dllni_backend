<?php

declare(strict_types=1);

use App\Enums\SupportCaseKind;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseReporterRole;
use App\Enums\SupportCaseStatus;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Models\SupportCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Models\SmOrder;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);
});

it('shows unified support cases inside restaurant supermarket and delivery order details', function (): void {
    $restaurant = Order::factory()->create(['order_number' => 'REST-SUPPORT-1']);
    $supermarket = SmOrder::factory()->create(['order_number' => 'SM-SUPPORT-2']);
    $delivery = DeliveryOrder::factory()->create(['order_number' => 'DEL-SUPPORT-3']);

    SupportCase::query()->create([
        'case_number' => 'CASE-REST-1',
        'kind' => SupportCaseKind::Complaint,
        'priority' => SupportCasePriority::High,
        'booking_id' => $restaurant->id,
        'booking_type' => Order::class,
        'reporter_id' => $restaurant->user_id,
        'reporter_role' => SupportCaseReporterRole::Customer,
        'category' => 'order_issue',
        'description' => 'Restaurant support case',
        'status' => SupportCaseStatus::New,
    ]);

    SupportCase::query()->create([
        'case_number' => 'CASE-SM-2',
        'kind' => SupportCaseKind::Complaint,
        'priority' => SupportCasePriority::Normal,
        'booking_id' => $supermarket->id,
        'booking_type' => SmOrder::class,
        'reporter_id' => $supermarket->customer_id,
        'reporter_role' => SupportCaseReporterRole::Customer,
        'category' => 'order_issue',
        'description' => 'Supermarket support case',
        'status' => SupportCaseStatus::New,
    ]);

    SupportCase::query()->create([
        'case_number' => 'CASE-DEL-3',
        'kind' => SupportCaseKind::Complaint,
        'priority' => SupportCasePriority::Critical,
        'booking_id' => $delivery->id,
        'booking_type' => 'delivery_order',
        'reporter_id' => $delivery->created_by_user_id,
        'reporter_role' => SupportCaseReporterRole::Customer,
        'category' => 'delivery_issue',
        'description' => 'Delivery support case',
        'status' => SupportCaseStatus::New,
    ]);

    $this->get(OrderResource::getUrl('view', ['record' => $restaurant], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('CASE-REST-1')
        ->assertSee('Restaurant support case');

    $this->get(SmOrderResource::getUrl('view', ['record' => $supermarket], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('CASE-SM-2')
        ->assertSee('Supermarket support case');

    $this->get(DeliveryOrderResource::getUrl('view', ['record' => $delivery], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('CASE-DEL-3')
        ->assertSee('Delivery support case');
});
