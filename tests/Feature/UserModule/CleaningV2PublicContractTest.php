<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBookingMaterial;
use Modules\Cleaning\Models\CleaningBookingSpecialServiceItem;
use Modules\Cleaning\Models\CleaningDirtinessLevel;
use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningMaterialTypeQuantityRule;
use Modules\Cleaning\Models\CleaningMaterialUnit;
use Modules\Cleaning\Models\CleaningSpecialService;

beforeEach(function (): void {
    CancellationPolicy::query()->create([
        'module' => 'cleaning',
        'name' => 'Cleaning v2 contract cancellation',
        'description' => 'Contract test policy',
        'rules' => ['free_until_hours' => 24],
        'is_active' => true,
        'is_default' => true,
    ]);
    CleaningBillingPolicy::query()->create([
        'name' => 'Cleaning v2 contract billing',
        'billing_mode' => CleaningBillingMode::FullBookedTime->value,
        'rules' => ['charge_full_booked_hours' => true],
        'is_active' => true,
        'is_default' => true,
    ]);
});

it('accepts canonical materials and special-service attachment contracts while preserving snapshots', function (): void {
    $unit = CleaningMaterialUnit::query()->create([
        'name' => 'Liter', 'code' => 'liter-contract', 'symbol' => 'L', 'is_active' => true,
    ]);
    $materialType = CleaningMaterialType::query()->create([
        'name' => 'Floor polish',
        'cleaning_material_unit_id' => $unit->id,
        'price_per_unit' => 40,
        'is_active' => true,
    ]);
    CleaningMaterialTypeQuantityRule::query()->create([
        'cleaning_material_type_id' => $materialType->id,
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'cleaning_mode' => 'regular',
        'quantity_per_room' => 0.25,
        'is_active' => true,
        'requires_admin_resolution' => false,
    ]);
    $product = CleaningMaterial::query()->create([
        'name' => 'Physical floor polish',
        'image_path' => 'cleaning/materials/polish.jpg',
        'cleaning_material_type_id' => $materialType->id,
        'stock_quantity' => 10,
        'low_stock_threshold' => 1,
        'is_active' => true,
    ]);

    $dirtiness = CleaningDirtinessLevel::query()->create([
        'name' => 'Heavy',
        'slug' => 'heavy-contract',
        'price_multiplier' => 1.5,
        'sort_order' => 1,
        'is_active' => true,
    ]);
    $service = CleaningSpecialService::query()->create([
        'name' => 'Sofa contract service',
        'slug' => 'sofa-contract',
        'pricing_unit' => 'square_meter',
        'input_type' => 'decimal',
        'unit_code' => 'm2',
        'base_unit_price' => 100,
        'supports_dirtiness' => true,
        'estimated_duration_minutes' => 60,
        'worker_pay_mode' => 'percentage',
        'worker_pay_value' => 50,
        'operating_cost_mode' => 'flat',
        'operating_cost_value' => 10,
        'travel_fee_mode' => 'flat',
        'travel_fee_value' => 0,
        'is_active' => true,
    ]);
    $service->dirtinessLevels()->attach($dirtiness->id);

    $customer = User::factory()->create();
    Sanctum::actingAs($customer);
    $response = $this->postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus contract address',
            'bedrooms' => 1,
            'bathrooms' => 0,
            'kitchens' => 0,
            'living_room_size' => 'small',
            'cleaning_mode' => 'regular',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
            ],
        ],
        'materials' => ['providedByPlatform' => true],
        'specialServices' => [[
            'serviceId' => $service->id,
            'items' => [[
                'quantity' => 1.5,
                'dirtinessLevelId' => $dirtiness->id,
                'notes' => 'Document this item',
                'attachments' => ['cleaning/before/sofa.jpg'],
            ]],
        ]],
        'scheduledDate' => now()->addDay()->toDateString(),
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'termsAccepted' => true,
    ])->assertCreated();

    $bookingId = (int) $response->json('order.id');
    $materialLine = CleaningBookingMaterial::query()->where('cleaning_booking_id', $bookingId)->sole();
    $item = CleaningBookingSpecialServiceItem::query()
        ->whereHas('bookingSpecialService', fn ($query) => $query->where('cleaning_booking_id', $bookingId))
        ->sole();

    expect((int) $materialLine->cleaning_material_type_id)->toBe($materialType->id)
        ->and((float) $materialLine->quantity)->toBe(0.25)
        ->and((float) $materialLine->unit_price)->toBe(40.0)
        ->and((float) $product->fresh()->stock_quantity)->toBe(9.75)
        ->and((float) $item->quantity)->toBe(1.5)
        ->and((int) $item->cleaning_dirtiness_level_id)->toBe($dirtiness->id)
        ->and($item->before_images)->toBe(['cleaning/before/sofa.jpg'])
        ->and((float) $item->total_price)->toBe(225.0);
});

it('applies open-time incompatibility checks to the canonical materials object', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'bedrooms' => 1,
            'bathrooms' => 1,
            'kitchens' => 0,
            'living_room_size' => 'small',
        ],
        'materials' => ['providedByPlatform' => true],
        'openTime' => ['workerCount' => 1, 'expectedMaxMinutes' => 120],
        'scheduledDate' => now()->addDay()->toDateString(),
        'scheduledTime' => '09:00',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('openTime');
});
