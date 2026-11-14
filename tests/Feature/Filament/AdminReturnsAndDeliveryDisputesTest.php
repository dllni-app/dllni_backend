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
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);
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
