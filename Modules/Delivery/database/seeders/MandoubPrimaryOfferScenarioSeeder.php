<?php

declare(strict_types=1);

namespace Modules\Delivery\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Delivery\Enums\DeliveryAssignmentAttemptStatus;
use Modules\Delivery\Enums\DeliveryDriverAvailabilityStatus;
use Modules\Delivery\Enums\DeliveryOrderStatus;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Models\DeliveryOrder;

final class MandoubPrimaryOfferScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $primaryDriver = $this->driverFor('+963900000001', 'mandoub.test@dllni.sy');
        $nearbyDriver = $this->driverFor('+963900000002', 'mandoub.nearby@dllni.sy');

        if (! $primaryDriver instanceof DeliveryDriver) {
            return;
        }

        $primaryDriver->forceFill([
            'availability_status' => DeliveryDriverAvailabilityStatus::Available->value,
            'is_active' => true,
            'is_suspended' => false,
            'suspended_until' => null,
            'suspension_reason' => null,
            'trust_score' => 100,
            'open_disputes_count' => 0,
            'last_seen_at' => now()->subMinute(),
        ])->save();

        $this->seedRecentLocation($primaryDriver);
        $this->moveActiveOrderAwayFromPrimary($primaryDriver, $nearbyDriver);
        $this->openOfferForPrimary($primaryDriver);
    }

    private function driverFor(string $phone, string $email): ?DeliveryDriver
    {
        $user = User::query()
            ->where('phone', $phone)
            ->orWhere('email', $email)
            ->first();

        if (! $user instanceof User) {
            return null;
        }

        return DeliveryDriver::query()->where('user_id', $user->id)->first();
    }

    private function seedRecentLocation(DeliveryDriver $driver): void
    {
        DeliveryDriverLocation::query()->updateOrCreate(
            [
                'driver_id' => $driver->id,
                'recorded_at' => now()->subMinute()->startOfMinute(),
            ],
            [
                'latitude' => 36.20230000,
                'longitude' => 37.13440000,
                'accuracy' => 2.8,
                'speed' => 12.0,
                'heading' => 90,
            ],
        );
    }

    private function moveActiveOrderAwayFromPrimary(DeliveryDriver $primaryDriver, ?DeliveryDriver $nearbyDriver): void
    {
        if (! $nearbyDriver instanceof DeliveryDriver) {
            return;
        }

        $activeOrder = DeliveryOrder::query()
            ->where('order_number', 'DLV-MANDOUB-ACTIVE')
            ->first();

        if (! $activeOrder instanceof DeliveryOrder) {
            return;
        }

        $activeOrder->forceFill(['driver_id' => $nearbyDriver->id])->save();

        DeliveryAssignmentAttempt::query()
            ->where('order_id', $activeOrder->id)
            ->where('driver_id', $primaryDriver->id)
            ->update(['driver_id' => $nearbyDriver->id]);
    }

    private function openOfferForPrimary(DeliveryDriver $primaryDriver): void
    {
        $offerOrder = DeliveryOrder::query()
            ->where('order_number', 'DLV-MANDOUB-OFFER')
            ->first();

        if (! $offerOrder instanceof DeliveryOrder) {
            return;
        }

        $offerOrder->forceFill([
            'driver_id' => null,
            'status' => DeliveryOrderStatus::Offered->value,
            'accepted_at' => null,
            'started_at' => null,
            'picked_up_at' => null,
            'delivered_at' => null,
            'completed_at' => null,
            'cancelled_at' => null,
            'stopped_at' => null,
            'cancel_reason' => null,
            'stop_reason' => null,
        ])->save();

        DeliveryAssignmentAttempt::query()
            ->where('order_id', $offerOrder->id)
            ->where('driver_id', '!=', $primaryDriver->id)
            ->where('status', DeliveryAssignmentAttemptStatus::Open->value)
            ->update([
                'status' => DeliveryAssignmentAttemptStatus::Cancelled->value,
                'responded_at' => now(),
            ]);

        DeliveryAssignmentAttempt::query()->updateOrCreate(
            [
                'order_id' => $offerOrder->id,
                'driver_id' => $primaryDriver->id,
                'attempt_no' => 1,
            ],
            [
                'status' => DeliveryAssignmentAttemptStatus::Open->value,
                'distance_to_pickup_km' => 0.9,
                'offered_at' => now()->subMinute(),
                'expires_at' => now()->addMinutes(10),
                'responded_at' => null,
                'reject_reason' => null,
            ],
        );

        $notificationData = json_encode([
            'module' => 'delivery',
            'type' => 'delivery_order_update',
            'category' => 'orders',
            'priority' => 'high',
            'title' => 'طلب توصيل جديد',
            'body' => 'يوجد عرض توصيل جديد بانتظار قرارك.',
            'message' => 'يوجد عرض توصيل جديد بانتظار قرارك.',
            'orderId' => $offerOrder->id,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        DB::table('notifications')->updateOrInsert(
            [
                'notifiable_type' => User::class,
                'notifiable_id' => $primaryDriver->user_id,
                'type' => \Modules\Delivery\Notifications\DeliveryCanonicalNotification::class,
                'data' => $notificationData,
            ],
            [
                'id' => (string) Str::uuid(),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}
