<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\PlatformCoupon;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterial;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialInventoryMovement;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningMaterialUnit;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningSpecialServiceDirtinessRule;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Services\CleaningMaterialInventoryService;
use Modules\Cleaning\Services\CleaningSpecialServiceQuoteService;

it('rolls back material reservation atomically when stock is insufficient', function (): void {
    $booking = CleaningBooking::factory()->create();
    $unit = CleaningMaterialUnit::query()->create([
        'name' => 'Bottle',
        'code' => 'agent-b-atomic-'.fake()->unique()->numerify('####'),
        'symbol' => 'btl',
        'is_active' => true,
    ]);
    $type = CleaningMaterialType::query()->create([
        'name' => 'Atomic detergent '.fake()->unique()->numerify('####'),
        'cleaning_material_unit_id' => $unit->id,
        'price_per_unit' => 25,
        'is_active' => true,
    ]);
    $material = CleaningMaterial::query()->create([
        'name' => 'Atomic cleaner '.fake()->unique()->numerify('####'),
        'cleaning_material_type_id' => $type->id,
        'stock_quantity' => 1,
        'low_stock_threshold' => 0,
        'is_active' => true,
    ]);
    $line = CleaningBookingMaterial::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_material_id' => $material->id,
        'cleaning_material_type_id' => $type->id,
        'cleaning_material_unit_id' => $unit->id,
        'quantity' => 2,
        'unit_price' => 25,
        'total_price' => 50,
        'inventory_status' => 'reserved',
    ]);

    expect(fn () => app(CleaningMaterialInventoryService::class)->reserve($line))
        ->toThrow(InvalidArgumentException::class);

    expect((float) $material->refresh()->stock_quantity)->toBe(1.0)
        ->and(CleaningMaterialInventoryMovement::query()
            ->where('cleaning_booking_material_id', $line->id)
            ->where('movement_type', CleaningMaterialInventoryMovement::RESERVED)
            ->count())->toBe(0);
});

it('crosses the material low-stock threshold once without double reserving', function (): void {
    $booking = CleaningBooking::factory()->create();
    $unit = CleaningMaterialUnit::query()->create([
        'name' => 'Liter',
        'code' => 'agent-b-low-'.fake()->unique()->numerify('####'),
        'symbol' => 'L',
        'is_active' => true,
    ]);
    $type = CleaningMaterialType::query()->create([
        'name' => 'Low stock detergent '.fake()->unique()->numerify('####'),
        'cleaning_material_unit_id' => $unit->id,
        'price_per_unit' => 10,
        'is_active' => true,
    ]);
    $material = CleaningMaterial::query()->create([
        'name' => 'Low stock cleaner '.fake()->unique()->numerify('####'),
        'cleaning_material_type_id' => $type->id,
        'stock_quantity' => 3,
        'low_stock_threshold' => 2,
        'is_active' => true,
    ]);
    $line = CleaningBookingMaterial::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_material_id' => $material->id,
        'cleaning_material_type_id' => $type->id,
        'cleaning_material_unit_id' => $unit->id,
        'quantity' => 1,
        'unit_price' => 10,
        'total_price' => 10,
        'inventory_status' => 'reserved',
    ]);

    $inventory = app(CleaningMaterialInventoryService::class);
    $inventory->reserve($line);
    $inventory->reserve($line->fresh());

    expect((float) $material->refresh()->stock_quantity)->toBe(2.0)
        ->and($material->isLowStock())->toBeTrue()
        ->and(CleaningMaterialInventoryMovement::query()
            ->where('cleaning_booking_material_id', $line->id)
            ->where('movement_type', CleaningMaterialInventoryMovement::RESERVED)
            ->count())->toBe(1);
});

it('keeps persisted special-service pricing and equipment snapshots immutable after catalog edits', function (): void {
    $service = CleaningSpecialService::query()->create([
        'name' => 'Snapshot sofa cleaning',
        'pricing_unit' => 'sofa',
        'base_unit_price' => 100,
        'is_active' => true,
    ]);
    $rule = CleaningSpecialServiceDirtinessRule::query()->create([
        'cleaning_special_service_id' => $service->id,
        'dirtiness_level' => 'heavy',
        'price_multiplier' => 1.5,
        'is_active' => true,
    ]);
    $equipment = CleaningSpecialServiceEquipment::query()->create([
        'name' => 'Snapshot extractor',
        'is_active' => true,
    ]);
    $service->equipment()->attach($equipment->id);

    $quote = app(CleaningSpecialServiceQuoteService::class)->quote([[
        'specialServiceId' => $service->id,
        'quantity' => 2,
        'dirtinessLevel' => 'heavy',
        'notes' => 'Original note',
    ]]);
    $quoted = $quote['lines'][0];
    $booking = CleaningBooking::factory()->create();
    $line = CleaningBookingSpecialService::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_special_service_id' => $service->id,
        'service_name' => $quoted['name'],
        'pricing_unit' => $quoted['pricingUnit'],
        'dirtiness_level' => $quoted['dirtinessLevel'],
        'quantity' => $quoted['quantity'],
        'base_unit_price' => $quoted['baseUnitPrice'],
        'price_multiplier' => $quoted['priceMultiplier'],
        'total_price' => $quoted['totalPrice'],
        'equipment_snapshot' => $quoted['equipment'],
        'notes' => $quoted['notes'],
    ]);

    $service->update(['name' => 'Changed later', 'base_unit_price' => 999]);
    $rule->update(['price_multiplier' => 3]);
    $equipment->update(['name' => 'Changed equipment']);

    $line->refresh();

    expect($line->service_name)->toBe('Snapshot sofa cleaning')
        ->and($line->pricing_unit)->toBe('sofa')
        ->and((float) $line->base_unit_price)->toBe(100.0)
        ->and((float) $line->price_multiplier)->toBe(1.5)
        ->and((float) $line->total_price)->toBe(300.0)
        ->and($line->equipment_snapshot)->toBe([
            ['id' => $equipment->id, 'name' => 'Snapshot extractor', 'assetCode' => null],
        ])
        ->and($line->notes)->toBe('Original note');
});

it('returns only active special services by default and allows explicit inactive filtering', function (): void {
    $active = CleaningSpecialService::query()->create([
        'name' => 'Active Agent B service',
        'pricing_unit' => 'piece',
        'base_unit_price' => 100,
        'is_active' => true,
    ]);
    $inactive = CleaningSpecialService::query()->create([
        'name' => 'Inactive Agent B service',
        'pricing_unit' => 'piece',
        'base_unit_price' => 100,
        'is_active' => false,
    ]);

    $defaultResponse = $this->getJson('/api/v1/cleaning-services?filter[category]=special_service&perPage=100')
        ->assertOk();
    $defaultIds = collect($defaultResponse->json('data'))->pluck('id')->map(fn ($id): int => (int) $id);

    expect($defaultIds->contains($active->id))->toBeTrue()
        ->and($defaultIds->contains($inactive->id))->toBeFalse();

    $inactiveResponse = $this->getJson('/api/v1/cleaning-services?filter[category]=special_service&filter[isActive]=0&perPage=100')
        ->assertOk();
    $inactiveIds = collect($inactiveResponse->json('data'))->pluck('id')->map(fn ($id): int => (int) $id);

    expect($inactiveIds->contains($inactive->id))->toBeTrue()
        ->and($inactiveIds->contains($active->id))->toBeFalse();
});

it('reduces open-time service only after a coupon exhausts the final administration margin', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 25,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 10,
            'travel_distance_start_point' => 'worker_home',
        ],
    );

    $coupon = PlatformCoupon::query()->create([
        'code' => 'AGENTB-OT-30',
        'title_ar' => 'كوبون وقت مفتوح',
        'title_en' => 'Open-Time coupon',
        'description_ar' => 'اختبار',
        'description_en' => 'Test',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => 30,
        'max_discount_amount' => null,
        'min_order_amount' => null,
        'audience_type' => PlatformCoupon::AUDIENCE_ALL_USERS,
        'total_usage_limit' => null,
        'per_user_usage_limit' => null,
        'used_count' => 0,
        'starts_at' => now()->subMinute(),
        'expires_at' => now()->addDay(),
        'is_active' => true,
    ]);
    $startedAt = now()->subMinutes(90)->startOfSecond();
    $worker = Worker::factory()->create([
        'home_address' => 'Same location',
        'home_latitude' => 36.2,
        'home_longitude' => 37.1,
    ]);
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress,
        'booking_kind' => 'open_time',
        'assignment_mode' => 'preferred_worker',
        'number_of_workers' => 1,
        'open_time_hourly_rate' => 200,
        'open_time_minimum_minutes' => 60,
        'open_time_rounding_minutes' => 30,
        'work_started_at' => $startedAt,
        'base_price' => 200,
        'addons_total' => 0,
        'travel_fee' => 10,
        'admin_margin_amount' => 50,
        'address_latitude' => 36.2,
        'address_longitude' => 37.1,
        'platform_coupon_id' => $coupon->id,
        'platform_coupon_code' => $coupon->code,
        'is_pricing_final' => false,
    ]);
    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
        'accepted_at' => now()->subHours(2),
        'work_started_at' => $startedAt,
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 200,
        'travel_fee' => 10,
        'admin_margin_amount' => 50,
        'worker_amount' => 210,
        'currency' => 'SYP',
    ]);

    $booking->update([
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
        'work_finished_at' => now()->startOfSecond(),
    ]);

    $booking->refresh();
    $assignment->refresh();

    expect((float) $booking->open_time_final_amount)->toBe(300.0)
        ->and((float) $booking->subtotal_before_discount)->toBe(385.0)
        ->and((float) $booking->discount_amount)->toBe(115.5)
        ->and((float) $booking->admin_margin_amount)->toBe(0.0)
        ->and((float) $booking->travel_fee)->toBe(10.0)
        ->and((float) $booking->total_price)->toBe(269.5)
        ->and((float) $assignment->service_share_amount)->toBe(259.5)
        ->and((float) $assignment->travel_fee)->toBe(10.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(0.0)
        ->and((float) $assignment->worker_amount)->toBe(269.5);
});

it('does not finalize open-time pricing when cancellation happens before a finish timestamp exists', function (CleaningBookingStatus $fromStatus, bool $started): void {
    $booking = CleaningBooking::factory()->create([
        'status' => $fromStatus,
        'booking_kind' => 'open_time',
        'number_of_workers' => 1,
        'open_time_hourly_rate' => 100,
        'open_time_minimum_minutes' => 60,
        'open_time_rounding_minutes' => 30,
        'work_started_at' => $started ? now()->subMinutes(20) : null,
        'work_finished_at' => null,
        'open_time_final_amount' => null,
        'open_time_finalized_at' => null,
        'is_pricing_final' => false,
    ]);

    $booking->update([
        'status' => CleaningBookingStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    $booking->refresh();

    expect($booking->open_time_finalized_at)->toBeNull()
        ->and($booking->open_time_final_amount)->toBeNull()
        ->and($booking->work_finished_at)->toBeNull()
        ->and($booking->is_pricing_final)->toBeFalse();
})->with([
    'before work starts' => [CleaningBookingStatus::WorkerAssigned, false],
    'after work starts but before finish' => [CleaningBookingStatus::InProgress, true],
]);

it('returns finalized open-time values and resolved special-service image through the user order api', function (): void {
    $customer = User::factory()->create();
    $service = CleaningSpecialService::query()->create([
        'name' => 'Uploaded-image service',
        'image_path' => 'cleaning-special-services/agent-b.png',
        'image_url' => 'https://legacy.example.test/service.png',
        'pricing_unit' => 'piece',
        'base_unit_price' => 100,
        'is_active' => true,
    ]);
    $startedAt = now()->subMinutes(75)->startOfSecond();
    $finishedAt = now()->startOfSecond();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
        'booking_kind' => 'open_time',
        'number_of_workers' => 2,
        'open_time_hourly_rate' => 100,
        'open_time_minimum_minutes' => 60,
        'open_time_rounding_minutes' => 30,
        'work_started_at' => $startedAt,
        'work_finished_at' => $finishedAt,
        'open_time_actual_minutes' => 75,
        'open_time_billable_minutes' => 90,
        'open_time_final_amount' => 300,
        'open_time_finalized_at' => $finishedAt,
        'is_pricing_final' => true,
    ]);
    CleaningBookingSpecialService::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_special_service_id' => $service->id,
        'service_name' => 'Uploaded-image service',
        'pricing_unit' => 'piece',
        'dirtiness_level' => 'normal',
        'quantity' => 1,
        'base_unit_price' => 100,
        'price_multiplier' => 1,
        'total_price' => 100,
        'equipment_snapshot' => [],
    ]);

    Sanctum::actingAs($customer);

    $response = $this->getJson("/api/v1/user/cleaning/orders/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.bookingKind', 'open_time')
        ->assertJsonPath('data.openTime.isOpenTime', true)
        ->assertJsonPath('data.openTime.requestedWorkerCount', 2)
        ->assertJsonPath('data.openTime.actualDurationMinutes', 75)
        ->assertJsonPath('data.openTime.billableDurationMinutes', 90)
        ->assertJsonPath('data.openTime.finalAmount', 300)
        ->assertJsonPath('data.openTime.isFinalized', true);

    $image = $response->json('data.specialServices.0.image');
    expect($image)->toBe($service->fresh()->imageUrl())
        ->and($image)->not->toBe('https://legacy.example.test/service.png');
});
