<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningFinancialSetting;
use App\Models\CleaningWorkerDeposit;
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

function createAgentBMaterialLine(CleaningBooking $booking, float $stock = 10, float $quantity = 2): array
{
    $unit = CleaningMaterialUnit::query()->create([
        'name' => 'Liter',
        'code' => 'liter-'.fake()->unique()->numerify('####'),
        'symbol' => 'L',
        'is_active' => true,
    ]);
    $type = CleaningMaterialType::query()->create([
        'name' => 'Detergent '.fake()->unique()->numerify('####'),
        'cleaning_material_unit_id' => $unit->id,
        'price_per_unit' => 25,
        'is_active' => true,
    ]);
    $material = CleaningMaterial::query()->create([
        'name' => 'Floor cleaner '.fake()->unique()->numerify('####'),
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

it('consumes a reserved material line once when work starts', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    [$material, $line] = createAgentBMaterialLine($booking);

    expect((float) $material->refresh()->stock_quantity)->toBe(8.0)
        ->and($line->refresh()->inventory_status)->toBe('reserved');

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

    $booking->forceFill(['updated_at' => now()->addSecond()])->save();

    expect(CleaningMaterialInventoryMovement::query()
        ->where('cleaning_booking_material_id', $line->id)
        ->where('movement_type', CleaningMaterialInventoryMovement::CONSUMED)
        ->count())->toBe(1);
});

it('releases reserved stock for every cancellation path', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    [$material, $line] = createAgentBMaterialLine($booking);

    $booking->update([
        'status' => CleaningBookingStatus::Cancelled,
        'cancelled_at' => now(),
    ]);

    expect($line->refresh()->inventory_status)->toBe('released')
        ->and((float) $material->refresh()->stock_quantity)->toBe(10.0)
        ->and(CleaningMaterialInventoryMovement::query()
            ->where('cleaning_booking_material_id', $line->id)
            ->where('movement_type', CleaningMaterialInventoryMovement::RELEASED)
            ->count())->toBe(1);
});

it('finalizes open-time billing from authoritative booking timestamps', function (): void {
    $startedAt = now()->subMinutes(61)->startOfSecond();
    $finishedAt = now()->startOfSecond();

    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress,
        'booking_kind' => 'open_time',
        'number_of_workers' => 1,
        'open_time_hourly_rate' => 200,
        'open_time_minimum_minutes' => 60,
        'open_time_rounding_minutes' => 30,
        'work_started_at' => $startedAt,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'is_pricing_final' => false,
    ]);

    $booking->update([
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
        'work_finished_at' => $finishedAt,
    ]);

    $booking->refresh();

    expect($booking->open_time_actual_minutes)->toBe(61)
        ->and($booking->open_time_billable_minutes)->toBe(90)
        ->and((float) $booking->open_time_final_amount)->toBe(300.0)
        ->and((float) $booking->base_price)->toBe(300.0)
        ->and((float) $booking->total_price)->toBe(300.0)
        ->and($booking->is_pricing_final)->toBeTrue()
        ->and($booking->open_time_finalized_at)->not->toBeNull();

    $finalizedAt = $booking->open_time_finalized_at;
    $booking->forceFill(['updated_at' => now()->addSecond()])->save();

    expect($booking->refresh()->open_time_finalized_at?->equalTo($finalizedAt))->toBeTrue();
});

it('reprices multi-worker open-time shares before completed commission settlement', function (): void {
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
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'restriction_threshold_percent' => 100,
            'allowance_warning_threshold_percent' => 10,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 50,
        ],
    );

    $startedAt = now()->subMinutes(90)->startOfSecond();
    $finishedAt = now()->startOfSecond();
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress,
        'booking_kind' => 'open_time',
        'assignment_mode' => 'open_count',
        'number_of_workers' => 2,
        'open_time_hourly_rate' => 100,
        'open_time_minimum_minutes' => 60,
        'open_time_rounding_minutes' => 30,
        'work_started_at' => $startedAt,
        'base_price' => 200,
        'addons_total' => 0,
        'address_latitude' => 36.2,
        'address_longitude' => 37.1,
        'is_pricing_final' => false,
    ]);

    $workers = collect([1, 2])->map(function (): Worker {
        $worker = Worker::factory()->create([
            'trust_score' => 80,
            'home_address' => 'Same location',
            'home_latitude' => 36.2,
            'home_longitude' => 37.1,
        ]);
        CleaningWorkerDeposit::query()->updateOrCreate(
            ['worker_id' => $worker->id],
            [
                'current_balance' => 1000,
                'debt_balance' => 0,
                'deposited_total' => 1000,
                'withdrawn_total' => 0,
                'minimum_required' => 0,
                'max_negative_balance' => 1000,
            ],
        );

        return $worker;
    });

    foreach ($workers as $worker) {
        CleaningBookingWorkerAssignment::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
            'accepted_at' => now()->subHours(2),
            'work_started_at' => $startedAt,
            'room_count' => 0,
            'rooms_weight' => 0,
            'service_share_amount' => 100,
            'travel_fee' => 10,
            'admin_margin_amount' => 25,
            'worker_amount' => 110,
            'currency' => 'SYP',
        ]);
    }

    // This deliberately uses the direct terminal path. The Completed observer
    // performs commission settlement, so the assertion proves Open-Time repricing
    // happened before the debit rather than in a later `updated` observer.
    $booking->update([
        'status' => CleaningBookingStatus::Completed,
        'work_finished_at' => $finishedAt,
    ]);

    $booking->refresh();
    $assignments = CleaningBookingWorkerAssignment::query()
        ->where('cleaning_booking_id', $booking->id)
        ->orderBy('id')
        ->get();

    expect((float) $booking->open_time_final_amount)->toBe(300.0)
        ->and($booking->open_time_actual_minutes)->toBe(90)
        ->and($booking->open_time_billable_minutes)->toBe(90)
        ->and((float) $assignments[0]->service_share_amount)->toBe(150.0)
        ->and((float) $assignments[1]->service_share_amount)->toBe(150.0)
        ->and((float) $assignments[0]->admin_margin_amount)->toBe(38.0)
        ->and((float) $assignments[1]->admin_margin_amount)->toBe(38.0)
        ->and((float) $booking->admin_margin_amount)->toBe(76.0)
        ->and((float) $booking->travel_fee)->toBe(20.0)
        ->and((float) $booking->total_price)->toBe(396.0);

    foreach ($workers as $worker) {
        $deposit = $worker->fresh()->deposit;
        expect((float) $deposit->current_balance)->toBe(962.0)
            ->and((float) $deposit->debt_balance)->toBe(0.0);
    }
});
