<?php

declare(strict_types=1);

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Filament\Resources\DeliveryDisputes\DeliveryDisputeResource;
use App\Filament\Resources\DeliveryDisputes\Pages\ViewDeliveryDispute;
use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\User;
use Database\Factories\SmProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Supermarket\Data\SmOrderReturnData;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Services\SmInventoryService;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);
});

it('shows supermarket return history from the inventory source of truth', function (): void {
    $order = SmOrder::factory()->create(['order_number' => 'SM-RETURN-360']);

    $product = SmProductFactory::new()->create([
        'store_id' => $order->store_id,
        'name' => 'Returned Milk',
        'stock_quantity' => 10,
    ]);

    $item = SmOrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => 'Returned Milk',
        'quantity' => 2,
        'unit_price' => 3000,
        'total_price' => 6000,
    ]);

    $actor = User::factory()->create();

    $data = SmOrderReturnData::from([
        'items' => [
            ['order_item_id' => $item->id, 'quantity' => 1],
        ],
        'reason' => 'Damaged package',
    ]);

    app(SmInventoryService::class)->processReturn($order, $data, $actor->id);

    expect((int) $product->fresh()->stock_quantity)->toBe(11)
        ->and($order->returnInventoryLogs()->count())->toBe(1);

    $this->get(SmOrderResource::getUrl('view', ['record' => $order], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('سجل الإرجاعات')
        ->assertSee('Returned Milk')
        ->assertSee('Damaged package')
        ->assertSee('11');
});

it('provides global delivery dispute visibility and audited status intervention', function (): void {
    $company = DeliveryCompany::factory()->create(['name' => 'Dispute Delivery Co']);
    $driver = DeliveryDriver::factory()->create([
        'company_id' => $company->id,
        'first_name' => 'Dispute Driver',
        'trust_score' => 100,
    ]);
    $order = DeliveryOrder::factory()->create([
        'company_id' => $company->id,
        'driver_id' => $driver->id,
        'order_number' => 'DEL-DISPUTE-001',
        'status' => 'completed',
    ]);

    $dispute = Dispute::query()->create([
        'booking_type' => 'delivery_order',
        'booking_id' => $order->id,
        'ticket_number' => 'DSP-ADMIN-001',
        'description' => 'Customer reported a delivery issue',
        'category' => DisputeCategory::Other->value,
        'status' => DisputeStatus::Open->value,
    ]);

    $this->get(DeliveryDisputeResource::getUrl('view', ['record' => $dispute], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('DSP-ADMIN-001')
        ->assertSee('DEL-DISPUTE-001')
        ->assertSee('Dispute Delivery Co')
        ->assertSee('Dispute Driver')
        ->assertSee('Customer reported a delivery issue');

    $this->get(DeliveryOrderResource::getUrl('view', ['record' => $order], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('DSP-ADMIN-001');

    Livewire::test(ViewDeliveryDispute::class, ['record' => $dispute->getRouteKey()])
        ->callAction('change_status', data: [
            'status' => DisputeStatus::Resolved->value,
            'reason' => 'Reviewed by platform operations',
        ])
        ->assertHasNoActionErrors();

    $dispute->refresh();

    expect($dispute->status)->toBe(DisputeStatus::Resolved)
        ->and(DisputeMessage::query()
            ->where('dispute_id', $dispute->id)
            ->where('body', 'like', '%Reviewed by platform operations%')
            ->exists())->toBeTrue()
        ->and(Activity::query()
            ->where('log_name', 'delivery_disputes_admin')
            ->where('subject_id', $dispute->id)
            ->exists())->toBeTrue();
});
