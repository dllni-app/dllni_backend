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
