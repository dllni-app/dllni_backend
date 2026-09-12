<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Notifications\CleaningMaterialLowStockDashboardNotification;
use App\Support\DashboardAdminRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
     * @param array<string,mixed> $line
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
