<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterial;
use Modules\Cleaning\Models\CleaningBookingMaterialKit;
use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningMaterialUnit;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Models\CleaningSupervisorAssignment;
use Modules\Cleaning\Services\CleaningEquipmentHandoverService;
use Modules\Cleaning\Services\CleaningMaterialInventoryService;
use Modules\Cleaning\Services\CleaningOperationalExtrasService;
use Spatie\Permission\Models\Role;

function lifecycleAdmin(): User
{
    Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    return $admin;
}

function lifecycleMaterial(CleaningBooking $booking, float $quantity = 3): array
{
    $unit = CleaningMaterialUnit::query()->create(['name' => 'Liter', 'code' => 'liter-'.uniqid(), 'is_active' => true]);
    $type = CleaningMaterialType::query()->create(['name' => 'Detergent', 'cleaning_material_unit_id' => $unit->id, 'price_per_unit' => 10, 'is_active' => true]);
    $material = CleaningMaterial::query()->create(['name' => 'Liquid cleaner', 'cleaning_material_type_id' => $type->id, 'stock_quantity' => 10, 'low_stock_threshold' => 0, 'is_active' => true]);
    $line = CleaningBookingMaterial::query()->create([
        'cleaning_booking_id' => $booking->id, 'cleaning_material_id' => $material->id,
        'cleaning_material_type_id' => $type->id, 'cleaning_material_unit_id' => $unit->id,
        'quantity' => $quantity, 'unit_price' => 10, 'total_price' => $quantity * 10, 'inventory_status' => 'reserved',
    ]);
    app(CleaningMaterialInventoryService::class)->reserve($line);

    return [$material, $line];
}

it('reconciles physical consumables exactly once and only restocks unused returns', function (): void {
    $admin = lifecycleAdmin();
    $booking = CleaningBooking::factory()->create();
    [$material, $line] = lifecycleMaterial($booking);
    CleaningBookingMaterialKit::query()->create(['cleaning_booking_id' => $booking->id, 'status' => CleaningBookingMaterialKit::RECEIVED]);
    $inventory = app(CleaningMaterialInventoryService::class);
    expect((float) $material->fresh()->stock_quantity)->toBe(7.0);

    $quantities = ['used' => 1.5, 'returned' => 1, 'damaged' => 0.25, 'lost' => 0.25];
    $inventory->reconcileUsage($line, $admin, $quantities, 'Return check with damaged bottle and missing remainder');
    $inventory->reconcileUsage($line, $admin, $quantities, 'Retry');

    expect((float) $material->fresh()->stock_quantity)->toBe(8.0)
        ->and($line->fresh()->inventory_status)->toBe('reconciled')
        ->and((float) $line->fresh()->used_quantity)->toBe(1.5)
        ->and(DB::table('cleaning_material_inventory_movements')->where('cleaning_booking_material_id', $line->id)->count())->toBe(5)
        ->and(DB::table('cleaning_operational_action_audits')->where('action', 'material_usage_reconciled')->count())->toBe(1);

    expect(fn () => $inventory->reconcileUsage($line, $admin, ['used' => 2, 'returned' => 1, 'damaged' => 0, 'lost' => 0], 'Changed'))
        ->toThrow(ValidationException::class);
    expect((float) $material->fresh()->stock_quantity)->toBe(8.0);
});

it('rejects incorrect or premature material reconciliation without changing stock', function (): void {
    $admin = lifecycleAdmin();
    $booking = CleaningBooking::factory()->create();
    [$material, $line] = lifecycleMaterial($booking);
    $kit = CleaningBookingMaterialKit::query()->create(['cleaning_booking_id' => $booking->id, 'status' => CleaningBookingMaterialKit::READY]);
    $inventory = app(CleaningMaterialInventoryService::class);

    expect(fn () => $inventory->reconcileUsage($line, $admin, ['used' => 2, 'returned' => 1, 'damaged' => 0, 'lost' => 0], 'Unchecked kit'))
        ->toThrow(ValidationException::class);
    $kit->forceFill(['status' => CleaningBookingMaterialKit::RECEIVED])->save();
    expect(fn () => $inventory->reconcileUsage($line, $admin, ['used' => 1, 'returned' => 1, 'damaged' => 0, 'lost' => 0], 'Incorrect sum'))
        ->toThrow(ValidationException::class);
    DB::table('cleaning_material_inventory_movements')->where('cleaning_booking_material_id', $line->id)
        ->where('movement_type', 'reserved')->delete();
    expect(fn () => $inventory->reconcileUsage($line, $admin, ['used' => 2, 'returned' => 1, 'damaged' => 0, 'lost' => 0], 'Missing issue ledger'))
        ->toThrow(ValidationException::class);
    expect((float) $material->fresh()->stock_quantity)->toBe(7.0)
        ->and($line->fresh()->inventory_status)->toBe('reserved')
        ->and(DB::table('cleaning_operational_action_audits')->where('action', 'material_usage_reconciled')->count())->toBe(0);
});

it('audits explicit physical stock adjustments without duplicating no-op ledger entries', function (): void {
    $admin = lifecycleAdmin();
    $booking = CleaningBooking::factory()->create();
    [$material] = lifecycleMaterial($booking);
    $inventory = app(CleaningMaterialInventoryService::class);

    expect(fn () => $inventory->adjustStock($material, User::factory()->create(), 12, 'Unapproved addition'))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    $inventory->adjustStock($material, $admin, 12, 'New bottles counted and recorded');
    $inventory->adjustStock($material, $admin, 12, 'Repeated count');

    expect((float) $material->fresh()->stock_quantity)->toBe(12.0)
        ->and((float) DB::table('cleaning_material_inventory_movements')->where('cleaning_material_id', $material->id)
            ->where('movement_type', 'adjusted')->value('quantity_delta'))->toBe(5.0)
        ->and(DB::table('cleaning_material_inventory_movements')->where('movement_type', 'adjusted')->count())->toBe(1)
        ->and(DB::table('cleaning_operational_action_audits')->where('action', 'material_stock_adjusted')->count())->toBe(1);
});

it('assigns reusable group equipment and material kits to the active supervisor, with per-asset partial returns', function (): void {
    $admin = lifecycleAdmin();
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $booking = CleaningBooking::factory()->create(['booking_kind' => 'supervised_team']);
    CleaningSupervisorAssignment::query()->create([
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $admin->id, 'assigned_at' => now(),
    ]);
    [$material] = lifecycleMaterial($booking);
    $kit = CleaningBookingMaterialKit::query()->create(['cleaning_booking_id' => $booking->id, 'status' => CleaningBookingMaterialKit::READY]);
    $received = app(CleaningMaterialInventoryService::class)->receiveKitForSupervisor($booking, $supervisor, $admin, 'Supervisor accepted sealed materials');
    expect($received->status)->toBe(CleaningBookingMaterialKit::RECEIVED)
        ->and($received->received_by_supervisor_user_id)->toBe($supervisor->id);
    expect(app(CleaningMaterialInventoryService::class)->receiveKitForSupervisor($booking, $supervisor, $admin, 'Retry')->id)->toBe($kit->id);
    expect(fn () => app(CleaningOperationalExtrasService::class)->receiveMaterialKit($booking, Worker::factory()->financiallyEligible()->create()))
        ->toThrow(ValidationException::class);

    $equipment = app(CleaningEquipmentHandoverService::class);
    $first = CleaningSpecialServiceEquipment::query()->create(['name' => 'Steam extractor', 'asset_code' => 'GROUP-A', 'status' => 'available', 'is_active' => true]);
    $second = CleaningSpecialServiceEquipment::query()->create(['name' => 'Vacuum', 'asset_code' => 'GROUP-B', 'status' => 'available', 'is_active' => true]);
    $firstLoan = $equipment->checkoutForBooking($booking, $first, $admin, 'Checked out to supervisor');
    $secondLoan = $equipment->checkoutForBooking($booking, $second, $admin, 'Checked out to supervisor');
    expect($firstLoan->supervisor_user_id)->toBe($supervisor->id)
        ->and($firstLoan->worker_id)->toBeNull()
        ->and($first->fresh()->status)->toBe('in_use')
        ->and($second->fresh()->status)->toBe('in_use');

    $equipment->reportGroupReturn($firstLoan, $admin, false, 'First asset returned in full');
    $equipment->confirmReturn($firstLoan, $admin, 'Checked and accepted');
    expect($first->fresh()->status)->toBe('available')
        ->and($second->fresh()->status)->toBe('in_use');
    $equipment->reportGroupReturn($secondLoan, $admin, true, 'Broken fan');
    $equipment->confirmReturn($secondLoan, $admin, 'Damage confirmed');
    expect($second->fresh()->status)->toBe('broken')
        ->and($secondLoan->fresh()->return_resolution)->toBe('maintenance')
        ->and(DB::table('cleaning_operational_action_audits')->where('cleaning_booking_id', $booking->id)->count())->toBe(7);

    expect((float) $material->fresh()->stock_quantity)->toBe(7.0);
});

it('records lost equipment as unavailable and prevents duplicate checkout', function (): void {
    $admin = lifecycleAdmin();
    $worker = Worker::factory()->financiallyEligible()->create();
    $booking = CleaningBooking::factory()->create(['worker_id' => $worker->id]);
    $asset = CleaningSpecialServiceEquipment::query()->create(['name' => 'Reusable tool', 'status' => 'available', 'is_active' => true]);
    $equipment = app(CleaningEquipmentHandoverService::class);
    $reservation = $equipment->checkoutForBooking($booking, $asset, $admin, 'Solo worker checkout');

    expect(fn () => $equipment->checkoutForBooking($booking, $asset, $admin, 'Duplicate'))
        ->toThrow(ValidationException::class);
    $equipment->confirmReturn($reservation, $admin, 'Asset not returned; search completed', 'lost');
    $equipment->confirmReturn($reservation, $admin, 'Retry', 'lost');
    expect($reservation->fresh()->status)->toBe('lost')
        ->and($asset->fresh()->status)->toBe('lost')
        ->and($reservation->fresh()->returned_at)->toBeNull()
        ->and(DB::table('cleaning_operational_action_audits')->where('cleaning_equipment_reservation_id', $reservation->id)->count())->toBe(2);
    expect(fn () => $equipment->checkoutForBooking($booking, $asset, $admin, 'Recheckout lost asset'))
        ->toThrow(ValidationException::class);
    expect(fn () => $asset->fresh()->update(['status' => 'available']))
        ->toThrow(ValidationException::class);
    $equipment->recoverLostEquipment($asset, $admin, 'Asset found, tested and returned to storage');
    expect($asset->fresh()->status)->toBe('available')
        ->and(DB::table('cleaning_operational_action_audits')->where('cleaning_equipment_reservation_id', $reservation->id)->count())->toBe(3);
    expect($equipment->checkoutForBooking($booking, $asset, $admin, 'Issued after recovery')->status)->toBe('handed_over');
});

it('exposes custodial inventory to only the assigned supervisor and lets them acknowledge and return individual assets over API', function (): void {
    $admin = lifecycleAdmin();
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $other = User::factory()->create(['is_active' => true]);
    $other->assignRole('Cleaning Supervisor');
    $booking = CleaningBooking::factory()->create(['booking_kind' => 'supervised_team', 'number_of_workers' => 2]);
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $admin->id, 'assigned_at' => now(), 'active_slot' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    [$material] = lifecycleMaterial($booking);
    CleaningBookingMaterialKit::query()->create(['cleaning_booking_id' => $booking->id, 'status' => CleaningBookingMaterialKit::READY]);
    $asset = CleaningSpecialServiceEquipment::query()->create(['name' => 'Extractor', 'status' => 'available', 'is_active' => true]);
    $reservation = app(CleaningEquipmentHandoverService::class)->checkoutForBooking($booking, $asset, $admin, 'Issue asset to supervisor');

    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}/inventory")->assertUnauthorized();
    Sanctum::actingAs($other);
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}/inventory")->assertNotFound();
    $this->postJson("/api/v1/supervisor/equipment-reservations/{$reservation->id}/acknowledge")->assertNotFound();
    Sanctum::actingAs($supervisor);
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}/inventory")
        ->assertOk()->assertJsonPath('data.materials.0.name', 'Liquid cleaner')
        ->assertJsonPath('data.equipment.0.supervisorUserId', $supervisor->id)
        ->assertJsonPath('data.equipment.0.status', 'handed_over');
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/materials/receive")
        ->assertOk()->assertJsonPath('data.materialKit.receivedBySupervisorUserId', $supervisor->id);
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/materials/receive")->assertOk();
    $this->postJson("/api/v1/supervisor/equipment-reservations/{$reservation->id}/acknowledge")
        ->assertOk()->assertJsonPath('data.reservation.status', 'acknowledged');
    $this->postJson("/api/v1/supervisor/equipment-reservations/{$reservation->id}/acknowledge")->assertOk();
    $this->postJson("/api/v1/supervisor/equipment-reservations/{$reservation->id}/return", ['failureReason' => 'Wheels jammed'])
        ->assertOk()->assertJsonPath('data.reservation.status', 'failure_pending_confirmation');
    expect($asset->fresh()->status)->toBe('in_use');
    app(CleaningEquipmentHandoverService::class)->confirmReturn($reservation, $admin, 'Repair required');
    expect($asset->fresh()->status)->toBe('broken')
        ->and(DB::table('cleaning_operational_action_audits')->where('cleaning_equipment_reservation_id', $reservation->id)->count())->toBe(4)
        ->and((float) $material->fresh()->stock_quantity)->toBe(7.0);
});

it('scopes worker inventory to their assigned solo booking and rejects other workers', function (): void {
    $admin = lifecycleAdmin();
    $worker = Worker::factory()->financiallyEligible()->create();
    $stranger = Worker::factory()->financiallyEligible()->create();
    $booking = CleaningBooking::factory()->create(['worker_id' => $worker->id]);
    lifecycleMaterial($booking);
    $asset = CleaningSpecialServiceEquipment::query()->create(['name' => 'Vacuum Cleaner', 'status' => 'available', 'is_active' => true]);
    app(CleaningEquipmentHandoverService::class)->checkoutForBooking($booking, $asset, $admin, 'Solo custody');

    Sanctum::actingAs($stranger->user);
    $this->getJson("/api/v1/cleaning/inventory/bookings/{$booking->id}")->assertNotFound();
    Sanctum::actingAs($worker->user);
    $this->getJson("/api/v1/cleaning/inventory/bookings/{$booking->id}")
        ->assertOk()->assertJsonPath('data.materials.0.name', 'Liquid cleaner')
        ->assertJsonPath('data.equipment.0.workerId', $worker->id)
        ->assertJsonPath('data.equipment.0.status', 'handed_over');
});

it('routes legacy multi-worker bookings with an active supervisor through group custody', function (): void {
    $admin = lifecycleAdmin();
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');
    $booking = CleaningBooking::factory()->create(['booking_kind' => 'standard', 'number_of_workers' => 3]);
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $admin->id, 'assigned_at' => now(), 'active_slot' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $asset = CleaningSpecialServiceEquipment::query()->create(['name' => 'Group floor scrubber', 'status' => 'available', 'is_active' => true]);
    $reservation = app(CleaningEquipmentHandoverService::class)->checkoutForBooking($booking, $asset, $admin, 'Issued under legacy group booking');
    expect($reservation->supervisor_user_id)->toBe($supervisor->id)
        ->and($reservation->worker_id)->toBeNull();

    Sanctum::actingAs($supervisor);
    $this->getJson("/api/v1/supervisor/bookings/{$booking->id}/inventory")
        ->assertOk()->assertJsonPath('data.equipment.0.supervisorUserId', $supervisor->id);
    $this->postJson("/api/v1/supervisor/equipment-reservations/{$reservation->id}/acknowledge")
        ->assertOk()->assertJsonPath('data.reservation.status', 'acknowledged');
});
