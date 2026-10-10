<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningBookingSpecialServiceItem;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningDirtinessLevel;
use Modules\Cleaning\Models\CleaningSpecialService;

function phase4SpecialCatalog(): array
{
    $light = CleaningDirtinessLevel::query()->create([
        'name' => 'Light', 'slug' => 'phase4-light', 'price_multiplier' => 1.0, 'sort_order' => 1, 'is_active' => true,
    ]);
    $heavy = CleaningDirtinessLevel::query()->create([
        'name' => 'Heavy', 'slug' => 'phase4-heavy', 'price_multiplier' => 1.5, 'sort_order' => 2, 'is_active' => true,
    ]);
    $service = CleaningSpecialService::query()->create([
        'name' => 'Sofa detail cleaning', 'slug' => 'phase4-sofa',
        'pricing_unit' => 'piece', 'base_unit_price' => 100,
        'supports_dirtiness' => true, 'is_active' => true,
    ]);
    $service->dirtinessLevels()->attach([$light->id, $heavy->id]);
    return [$service, $light, $heavy];
}

function phase4BookingPayload(CleaningSpecialService $service, CleaningDirtinessLevel $light, CleaningDirtinessLevel $heavy): array
{
    return [
        'bookingKind' => 'special_service',
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus special service',
            'bedrooms' => 1, 'bathrooms' => 0, 'kitchens' => 0, 'living_room_size' => 'small',
            'cleaning_mode' => 'regular',
            'room_size_breakdown' => ['bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0]],
        ],
        'specialServices' => [[
            'serviceId' => $service->id,
            'items' => [
                ['quantity' => 1, 'dirtinessLevelId' => $light->id, 'notes' => 'Single cushion', 'beforeImages' => ['cleaning/before/one.jpg']],
                ['quantity' => 2, 'dirtinessLevelId' => $heavy->id, 'notes' => 'Long sofa', 'beforeImages' => ['cleaning/before/two.jpg']],
            ],
        ]],
        'scheduledDate' => now()->addDay()->toDateString(),
        'scheduledTime' => '10:00',
        'addressLatitude' => 33.5138, 'addressLongitude' => 36.2765,
        'termsAccepted' => true,
    ];
}

it('allows the customer to edit itemized standalone services and persists authoritative prices and photo evidence', function (): void {
    [$service, $light, $heavy] = phase4SpecialCatalog();
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);
    $created = $this->postJson('/api/v1/user/cleaning/orders', phase4BookingPayload($service, $light, $heavy))->assertCreated();
    $id = (int) $created->json('order.id');

    $response = $this->patchJson("/api/v1/user/cleaning/orders/{$id}", [
        'specialServices' => [[
            'specialServiceId' => $service->id,
            'items' => [
                ['quantity' => 3, 'dirtinessLevelId' => $light->id, 'notes' => 'Three cushions', 'attachments' => ['cleaning/before/three.jpg']],
                ['quantity' => 1, 'dirtinessLevelId' => $heavy->id, 'notes' => 'Large sofa', 'beforeImages' => ['cleaning/before/large.jpg']],
            ],
        ]],
    ]);
    $response->assertOk()
        ->assertJsonPath('order.specialServices.0.items.0.quantity', 3)
        ->assertJsonPath('order.specialServices.0.items.0.beforeImages.0', 'cleaning/before/three.jpg')
        ->assertJsonPath('order.specialServices.0.items.1.beforeImages.0', 'cleaning/before/large.jpg');
    expect((string) $response->json('order.specialServices.0.items.0.beforeImageUrls.0'))
        ->toEndWith('/storage/cleaning/before/three.jpg');

    $line = CleaningBookingSpecialService::query()->where('cleaning_booking_id', $id)->sole();
    $items = $line->items()->orderBy('id')->get();
    expect($items)->toHaveCount(2)
        ->and((float) $line->total_price)->toBe(450.0)
        ->and((float) $items[0]->total_price)->toBe(300.0)
        ->and((float) $items[1]->total_price)->toBe(150.0)
        ->and($items[0]->notes)->toBe('Three cushions')
        ->and(DB::table('cleaning_operational_action_audits')->where('cleaning_booking_id', $id)->where('action', 'customer_special_services_updated')->count())->toBe(1);
});

it('does not collapse existing service items when other pricing inputs change', function (): void {
    [$service, $light, $heavy] = phase4SpecialCatalog();
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);
    $created = $this->postJson('/api/v1/user/cleaning/orders', phase4BookingPayload($service, $light, $heavy))->assertCreated();
    $id = (int) $created->json('order.id');

    $this->patchJson("/api/v1/user/cleaning/orders/{$id}", [
        'requestMaterials' => false,
    ])->assertOk();

    $line = CleaningBookingSpecialService::query()->where('cleaning_booking_id', $id)->sole();
    $items = $line->items()->orderBy('id')->get();
    expect($items)->toHaveCount(2)
        ->and((float) $items[0]->quantity)->toBe(1.0)
        ->and((float) $items[1]->quantity)->toBe(2.0)
        ->and($items[0]->before_images)->toBe(['cleaning/before/one.jpg'])
        ->and($items[1]->before_images)->toBe(['cleaning/before/two.jpg'])
        ->and((float) $line->total_price)->toBe(400.0);
});

it('rejects removing all services from a standalone booking or replacing lines after worker acceptance', function (): void {
    [$service, $light, $heavy] = phase4SpecialCatalog();
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);
    $created = $this->postJson('/api/v1/user/cleaning/orders', phase4BookingPayload($service, $light, $heavy))->assertCreated();
    $id = (int) $created->json('order.id');
    $this->patchJson("/api/v1/user/cleaning/orders/{$id}", ['specialServices' => []])
        ->assertUnprocessable()->assertJsonValidationErrors('specialServices');

    $worker = Worker::factory()->financiallyEligible()->create();
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Accepted->value,
        'accepted_at' => now(),
    ]);
    $this->patchJson("/api/v1/user/cleaning/orders/{$id}", [
        'specialServices' => [[
            'serviceId' => $service->id,
            'items' => [['quantity' => 99, 'dirtinessLevelId' => $heavy->id]],
        ]],
    ])->assertUnprocessable()->assertJsonValidationErrors('order');

    expect((float) CleaningBookingSpecialService::query()->where('cleaning_booking_id', $id)->sole()->total_price)->toBe(400.0)
        ->and(DB::table('cleaning_operational_action_audits')->where('cleaning_booking_id', $id)->where('action', 'customer_special_services_updated')->count())->toBe(0);
});
