<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\PlatformCoupon;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterial;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialInventoryMovement;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningMaterialUnit;
use Modules\Cleaning\Services\CleaningMaterialInventoryService;

function createAgentBRegressionMaterialLine(
    CleaningBooking $booking,
    float $stock = 10,
    float $quantity = 2,
): array {
    $unit = CleaningMaterialUnit::query()->create([
        'name' => 'Liter',
        'code' => 'agent-b-liter-'.fake()->unique()->numerify('####'),
        'symbol' => 'L',
        'is_active' => true,
    ]);
    $type = CleaningMaterialType::query()->create([
        'name' => 'Agent B detergent '.fake()->unique()->numerify('####'),
        'cleaning_material_unit_id' => $unit->id,
        'price_per_unit' => 25,
        'is_active' => true,
    ]);
    $material = CleaningMaterial::query()->create([
        'name' => 'Agent B cleaner '.fake()->unique()->numerify('####'),
        'cleaning_material_type_id' => $type->id,
        'stock_quantity' => $stock,
        'low_stock_threshold' => 1,
        'is_active' => true,
    ]);
    $line = CleaningBookingMaterial::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_material_id' => $material->id,
        'cleaning_material_type_id' => $type->id,
        'cleaning_material_unit_id' => $unit->id,
        'quantity' => $quantity,
        'unit_price' => 25,
        'total_price' => 25 * $quantity,
        'inventory_status' => 'reserved',
    ]);

    app(CleaningMaterialInventoryService::class)->reserve($line);

    return [$material, $line];
}

function createAgentBRegressionPercentageCoupon(string $code, float $discount): PlatformCoupon
{
    return PlatformCoupon::query()->create([
        'code' => $code,
        'title_ar' => 'كوبون اختبار Agent B',
        'title_en' => 'Agent B regression coupon',
        'description_ar' => 'اختبار تسعير الوقت المفتوح',
        'description_en' => 'Open-Time pricing regression test',
        'section' => PlatformCoupon::SECTION_CLEANING,
        'discount_type' => PlatformCoupon::DISCOUNT_PERCENTAGE,
        'discount_value' => $discount,
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
}

it('does not restore already consumed material stock when the booking is cancelled later', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    [$material, $line] = createAgentBRegressionMaterialLine($booking);

    $booking->update([
        'status' => CleaningBookingStatus::InProgress,
        'work_started_at' => now(),
    ]);

    expect($line->refresh()->inventory_status)->toBe('consumed')
        ->and((float) $material->refresh()->stock_quantity)->toBe(8.0)
        ->and(CleaningMaterialInventoryMovement::query()
            ->where('cleaning_booking_material_id', $line->id)
            ->where('movement_type', CleaningMaterialInventoryMovement::CONSUMED)
            ->count())->toBe(1);

    $booking->update([
        'status' => CleaningBookingStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    expect($line->refresh()->inventory_status)->toBe('consumed')
        ->and((float) $material->refresh()->stock_quantity)->toBe(8.0)
        ->and(CleaningMaterialInventoryMovement::query()
            ->where('cleaning_booking_material_id', $line->id)
            ->where('movement_type', CleaningMaterialInventoryMovement::RELEASED)
            ->count())->toBe(0);
});

it('applies the coupon only after authoritative open-time repricing and keeps travel undiscounted', function (): void {
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

    $coupon = createAgentBRegressionPercentageCoupon('AGENTB-OT-10', 10);
    $startedAt = now()->subMinutes(90)->startOfSecond();
    $finishedAt = now()->startOfSecond();
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
        'total_price' => 260,
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
        'work_finished_at' => $finishedAt,
    ]);

    $booking->refresh();
    $assignment->refresh();

    // 90 minutes at 200 SYP/hour = 300 service. Canonical final worker pricing
    // adds 75 admin (25%) and the 10 SYP minimum travel fee, so gross is 385.
    // The 10% coupon is therefore 38.50 and is funded entirely by admin. Travel
    // remains 10 and the worker service share remains the full 300.
    expect((float) $booking->open_time_final_amount)->toBe(300.0)
        ->and($booking->open_time_actual_minutes)->toBe(90)
        ->and($booking->open_time_billable_minutes)->toBe(90)
        ->and((float) $booking->base_price)->toBe(300.0)
        ->and((float) $booking->subtotal_before_discount)->toBe(385.0)
        ->and((float) $booking->discount_amount)->toBe(38.5)
        ->and((float) $booking->travel_fee)->toBe(10.0)
        ->and((float) $booking->admin_margin_amount)->toBe(36.5)
        ->and((float) $booking->total_price)->toBe(346.5)
        ->and((float) $assignment->service_share_amount)->toBe(300.0)
        ->and((float) $assignment->travel_fee)->toBe(10.0)
        ->and((float) $assignment->admin_margin_amount)->toBe(36.5)
        ->and((float) $assignment->worker_amount)->toBe(310.0)
        ->and($booking->is_pricing_final)->toBeTrue();
});
