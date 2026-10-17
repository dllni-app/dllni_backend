<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Notifications\CleaningMaterialLowStockDashboardNotification;
use App\Support\DashboardAdminRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterial;
use Modules\Cleaning\Models\CleaningBookingMaterialKit;
use Modules\Cleaning\Models\CleaningMaterial;
use Modules\Cleaning\Models\CleaningMaterialInventoryMovement;

final class CleaningMaterialInventoryService
{
    public function __construct(
        private readonly CleaningMaterialLowStockPolicy $lowStockPolicy,
    ) {}

    public function reserve(CleaningBookingMaterial $bookingMaterial): void
    {
        DB::transaction(function () use ($bookingMaterial): void {
            $bookingMaterial = CleaningBookingMaterial::query()->whereKey($bookingMaterial->id)->lockForUpdate()->firstOrFail();
            if ($bookingMaterial->inventory_status !== 'reserved') {
                return;
            }

            $existing = CleaningMaterialInventoryMovement::query()
                ->where('cleaning_booking_material_id', $bookingMaterial->id)
                ->where('movement_type', CleaningMaterialInventoryMovement::RESERVED)
                ->exists();
            if ($existing) {
                return;
            }

            $material = CleaningMaterial::query()->whereKey($bookingMaterial->cleaning_material_id)->lockForUpdate()->firstOrFail();
            $quantity = (float) $bookingMaterial->quantity;
            $stockBefore = (float) $material->stock_quantity;
            if ($stockBefore < $quantity) {
                throw new InvalidArgumentException("Insufficient stock for {$material->name}.");
            }

            $stockAfter = round($stockBefore - $quantity, 3);
            $material->forceFill(['stock_quantity' => $stockAfter])->save();
            $this->record($bookingMaterial, CleaningMaterialInventoryMovement::RESERVED, -$quantity);
            $this->scheduleLowStockNotificationIfThresholdCrossed($material, $stockBefore, $stockAfter);
        });
    }

    /**
     * Reserve one customer-facing material-type line across physical stock
     * products in deterministic FIFO order.
     *
     * @param  array<string,mixed>  $line
     * @return array<int,CleaningBookingMaterial>
     */
    public function reserveTypeLine(CleaningBooking $booking, array $line): array
    {
        return DB::transaction(function () use ($booking, $line): array {
            $typeId = (int) ($line['materialTypeId'] ?? 0);
            $unitId = (int) ($line['unitId'] ?? 0);
            $requested = round(max(0.0, (float) ($line['quantity'] ?? 0)), 3);
            if ($typeId <= 0 || $unitId <= 0 || $requested <= 0) {
                throw new InvalidArgumentException('A valid material type, unit, and quantity are required.');
            }

            $products = CleaningMaterial::query()
                ->where('cleaning_material_type_id', $typeId)
                ->where('is_active', true)
                ->where('stock_quantity', '>', 0)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $available = round((float) $products->sum('stock_quantity'), 3);
            if ($available < $requested) {
                throw new InvalidArgumentException('Insufficient stock to prepare the requested material kit.');
            }

            $remaining = $requested;
            $created = [];
            foreach ($products as $product) {
                if ($remaining <= 0) {
                    break;
                }
                $stockBefore = round((float) $product->stock_quantity, 3);
                $allocated = round(min($remaining, $stockBefore), 3);
                if ($allocated <= 0) {
                    continue;
                }

                $bookingMaterial = CleaningBookingMaterial::query()->create([
                    'cleaning_booking_id' => $booking->id,
                    'cleaning_material_id' => $product->id,
                    'cleaning_material_type_id' => $typeId,
                    'cleaning_material_unit_id' => $unitId,
                    'quantity' => $allocated,
                    'unit_price' => (float) ($line['unitPrice'] ?? 0),
                    'total_price' => round($allocated * (float) ($line['unitPrice'] ?? 0), 2),
                    'inventory_status' => 'reserved',
                ]);
                $stockAfter = round($stockBefore - $allocated, 3);
                $product->forceFill(['stock_quantity' => $stockAfter])->save();
                $this->record($bookingMaterial, CleaningMaterialInventoryMovement::RESERVED, -$allocated);
                $this->scheduleLowStockNotificationIfThresholdCrossed($product, $stockBefore, $stockAfter);
                $created[] = $bookingMaterial;
                $remaining = round($remaining - $allocated, 3);
            }

            CleaningBookingMaterialKit::query()->firstOrCreate(
                ['cleaning_booking_id' => $booking->id],
                ['status' => CleaningBookingMaterialKit::PREPARING],
            );

            return $created;
        }, 3);
    }

    public function consumeForBooking(CleaningBooking $booking): void
    {
        DB::transaction(function () use ($booking): void {
            CleaningBookingMaterial::query()
                ->where('cleaning_booking_id', $booking->id)
                ->where('inventory_status', 'reserved')
                ->lockForUpdate()
                ->get()
                ->each(function (CleaningBookingMaterial $bookingMaterial): void {
                    $this->record($bookingMaterial, CleaningMaterialInventoryMovement::CONSUMED, 0.0);
                    $bookingMaterial->forceFill(['inventory_status' => 'consumed'])->save();
                });
        });
    }

    public function releaseForBooking(CleaningBooking $booking): void
    {
        DB::transaction(function () use ($booking): void {
            CleaningBookingMaterial::query()
                ->where('cleaning_booking_id', $booking->id)
                ->where('inventory_status', 'reserved')
                ->lockForUpdate()
                ->get()
                ->each(function (CleaningBookingMaterial $bookingMaterial): void {
                    $material = CleaningMaterial::query()->whereKey($bookingMaterial->cleaning_material_id)->lockForUpdate()->firstOrFail();
                    $quantity = (float) $bookingMaterial->quantity;
                    $material->forceFill(['stock_quantity' => round((float) $material->stock_quantity + $quantity, 3)])->save();
                    $this->record($bookingMaterial, CleaningMaterialInventoryMovement::RELEASED, $quantity);
                    $bookingMaterial->forceFill(['inventory_status' => 'released'])->save();
                });
        });
    }

    /**
     * Account for the actual disposition of an issued consumable line. Stock was
     * already debited at reservation; only unused quantities return to stock.
     *
     * @param  array{used: numeric-string|int|float, returned: numeric-string|int|float, damaged: numeric-string|int|float, lost: numeric-string|int|float}  $quantities
     */
    public function reconcileUsage(CleaningBookingMaterial $line, User $admin, array $quantities, string $note): CleaningBookingMaterial
    {
        abort_unless($admin->hasAnyRole(['admin', 'Super Admin']) || $admin->can('cleaning_bookings.update'), 403);
        $note = mb_trim($note);
        if ($note === '' || mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => 'A reconciliation note is required (maximum 2000 characters).']);
        }

        $parts = [];
        foreach (['used', 'returned', 'damaged', 'lost'] as $key) {
            $raw = $quantities[$key] ?? null;
            if (! is_numeric($raw) || (float) $raw < 0 || abs(round((float) $raw, 3) - (float) $raw) > 0.000001) {
                throw ValidationException::withMessages([$key => 'Quantity must be a non-negative number with up to three decimals.']);
            }
            $parts[$key] = (int) round((float) $raw * 1000);
        }

        return DB::transaction(function () use ($line, $admin, $parts, $note): CleaningBookingMaterial {
            $locked = CleaningBookingMaterial::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            if (array_sum($parts) !== (int) round((float) $locked->quantity * 1000)) {
                throw ValidationException::withMessages(['quantity' => 'Used, returned, damaged and lost quantities must equal the issued quantity.']);
            }
            if ($locked->inventory_status === 'reconciled') {
                $matching = collect(['used', 'returned', 'damaged', 'lost'])->every(
                    fn (string $key): bool => (int) round((float) $locked->{$key.'_quantity'} * 1000) === $parts[$key]
                );
                if ($matching) {
                    return $locked;
                }
                throw ValidationException::withMessages(['quantity' => 'This material line has already been reconciled with different quantities.']);
            }
            if (! in_array($locked->inventory_status, ['reserved', 'consumed'], true)) {
                throw ValidationException::withMessages(['material' => 'Only issued material may be reconciled.']);
            }
            if (! CleaningMaterialInventoryMovement::query()->where('cleaning_booking_material_id', $locked->id)
                ->where('movement_type', CleaningMaterialInventoryMovement::RESERVED)->exists()) {
                throw ValidationException::withMessages(['material' => 'This material has no recorded stock issue to reconcile.']);
            }
            $kit = CleaningBookingMaterialKit::query()->where('cleaning_booking_id', $locked->cleaning_booking_id)->first();
            if ($kit !== null && $kit->status !== CleaningBookingMaterialKit::RECEIVED) {
                throw ValidationException::withMessages(['kit' => 'The issued material kit must be received first.']);
            }

            $material = CleaningMaterial::query()->whereKey($locked->cleaning_material_id)->lockForUpdate()->firstOrFail();
            $before = (float) $material->stock_quantity;
            if ($locked->inventory_status === 'reserved') {
                $this->record($locked, CleaningMaterialInventoryMovement::CONSUMED, 0.0);
            }
            $returned = $parts['returned'] / 1000;
            if ($returned > 0) {
                $material->forceFill(['stock_quantity' => round($before + $returned, 3)])->save();
                $this->recordDisposition($locked, CleaningMaterialInventoryMovement::RETURNED_UNUSED, $returned, $note);
            }
            foreach (['damaged' => CleaningMaterialInventoryMovement::DAMAGED, 'lost' => CleaningMaterialInventoryMovement::LOST] as $key => $movement) {
                if ($parts[$key] > 0) {
                    $this->recordDisposition($locked, $movement, 0.0, $key.' quantity: '.($parts[$key] / 1000).'. '.$note);
                }
            }
            $locked->forceFill([
                'inventory_status' => 'reconciled',
                'used_quantity' => $parts['used'] / 1000,
                'returned_quantity' => $returned,
                'damaged_quantity' => $parts['damaged'] / 1000,
                'lost_quantity' => $parts['lost'] / 1000,
                'reconciled_at' => now(),
                'reconciled_by_user_id' => $admin->id,
            ])->save();
            DB::table('cleaning_operational_action_audits')->insert([
                'action' => 'material_usage_reconciled',
                'cleaning_booking_id' => $locked->cleaning_booking_id,
                'actor_user_id' => $admin->id,
                'reason' => $note,
                'before_snapshot' => json_encode(['materialLineId' => $locked->id, 'stock' => $before, 'issued' => (float) $locked->quantity], JSON_THROW_ON_ERROR),
                'after_snapshot' => json_encode(['materialLineId' => $locked->id, 'stock' => (float) $material->stock_quantity, 'quantities' => array_map(fn (int $n): float => $n / 1000, $parts)], JSON_THROW_ON_ERROR),
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    public function receiveKitForSupervisor(CleaningBooking $booking, User $supervisor, ?User $admin, string $note): CleaningBookingMaterialKit
    {
        if ($admin !== null) {
            abort_unless($admin->hasAnyRole(['admin', 'Super Admin']) || $admin->can('cleaning_bookings.update'), 403);
        } else {
            app(SupervisedTeamService::class)->assertSupervisor($supervisor);
        }
        $note = mb_trim($note);
        if ($note === '' || mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => 'A supervisor kit handover note is required (maximum 2000 characters).']);
        }

        return DB::transaction(function () use ($booking, $supervisor, $admin, $note): CleaningBookingMaterialKit {
            $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            app(CleaningGroupCustodyPolicy::class)->assertActiveSupervisor($lockedBooking, $supervisor);
            $kit = CleaningBookingMaterialKit::query()->where('cleaning_booking_id', $booking->id)->lockForUpdate()->firstOrFail();
            if ($kit->status === CleaningBookingMaterialKit::RECEIVED && (int) $kit->received_by_supervisor_user_id === (int) $supervisor->id) {
                return $kit;
            }
            if ($kit->status !== CleaningBookingMaterialKit::READY) {
                throw ValidationException::withMessages(['kit' => 'Only a prepared kit may be handed over to the supervisor.']);
            }
            $kit->forceFill(['status' => CleaningBookingMaterialKit::RECEIVED, 'received_by_supervisor_user_id' => $supervisor->id, 'received_at' => now()])->save();
            DB::table('cleaning_operational_action_audits')->insert([
                'action' => 'material_kit_supervisor_received', 'cleaning_booking_id' => $booking->id,
                'actor_user_id' => $admin?->id ?? $supervisor->id, 'reason' => mb_substr(mb_trim($note), 0, 2000),
                'before_snapshot' => json_encode(['status' => 'ready'], JSON_THROW_ON_ERROR),
                'after_snapshot' => json_encode(['status' => 'received', 'supervisorUserId' => $supervisor->id], JSON_THROW_ON_ERROR),
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $kit->fresh() ?? $kit;
        }, 3);
    }

    public function adjustStock(CleaningMaterial $material, User $admin, float $newQuantity, string $note): CleaningMaterial
    {
        abort_unless($admin->hasAnyRole(['admin', 'Super Admin']) || $admin->can('pricing.update'), 403);
        $note = mb_trim($note);
        if (! is_finite($newQuantity) || $newQuantity < 0 || round($newQuantity, 3) !== $newQuantity || $note === '') {
            throw ValidationException::withMessages(['stock_quantity' => 'Supply a valid stock quantity (three decimals maximum) and an adjustment reason.']);
        }

        return DB::transaction(function () use ($material, $admin, $newQuantity, $note): CleaningMaterial {
            $locked = CleaningMaterial::query()->whereKey($material->id)->lockForUpdate()->firstOrFail();
            $before = (float) $locked->stock_quantity;
            $newQuantity = round($newQuantity, 3);
            $delta = round($newQuantity - $before, 3);
            if ($delta === 0.0) {
                return $locked;
            }
            $locked->forceFill(['stock_quantity' => $newQuantity])->save();
            CleaningMaterialInventoryMovement::query()->create([
                'cleaning_material_id' => $locked->id, 'movement_type' => CleaningMaterialInventoryMovement::ADJUSTED,
                'quantity_delta' => $delta, 'reference_type' => User::class,
                'reference_id' => $admin->id, 'note' => mb_substr($note, 0, 500),
            ]);
            DB::table('cleaning_operational_action_audits')->insert([
                'action' => 'material_stock_adjusted', 'actor_user_id' => $admin->id, 'reason' => mb_substr($note, 0, 2000),
                'before_snapshot' => json_encode(['materialId' => $locked->id, 'stock' => $before], JSON_THROW_ON_ERROR),
                'after_snapshot' => json_encode(['materialId' => $locked->id, 'stock' => $newQuantity, 'delta' => $delta], JSON_THROW_ON_ERROR),
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->scheduleLowStockNotificationIfThresholdCrossed($locked, $before, $newQuantity);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    private function recordDisposition(CleaningBookingMaterial $line, string $type, float $delta, string $note): void
    {
        CleaningMaterialInventoryMovement::query()->create([
            'cleaning_material_id' => $line->cleaning_material_id,
            'cleaning_booking_material_id' => $line->id,
            'movement_type' => $type,
            'quantity_delta' => $delta,
            'reference_type' => CleaningBooking::class,
            'reference_id' => $line->cleaning_booking_id,
            'note' => mb_substr($note, 0, 500),
        ]);
    }

    private function record(CleaningBookingMaterial $bookingMaterial, string $movementType, float $quantityDelta): void
    {
        CleaningMaterialInventoryMovement::query()->firstOrCreate(
            [
                'cleaning_booking_material_id' => $bookingMaterial->id,
                'movement_type' => $movementType,
            ],
            [
                'cleaning_material_id' => $bookingMaterial->cleaning_material_id,
                'quantity_delta' => $quantityDelta,
                'reference_type' => CleaningBooking::class,
                'reference_id' => $bookingMaterial->cleaning_booking_id,
            ],
        );
    }

    private function scheduleLowStockNotificationIfThresholdCrossed(
        CleaningMaterial $material,
        float $stockBefore,
        float $stockAfter,
    ): void {
        if (! $this->lowStockPolicy->shouldNotify($material, $stockBefore, $stockAfter)) {
            return;
        }

        $materialId = (int) $material->id;
        DB::afterCommit(static function () use ($materialId): void {
            $fresh = CleaningMaterial::query()->find($materialId);
            if (! $fresh instanceof CleaningMaterial || ! $fresh->isLowStock()) {
                return;
            }

            $recipients = DashboardAdminRecipients::all();
            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, new CleaningMaterialLowStockDashboardNotification($fresh));
        });
    }
}
