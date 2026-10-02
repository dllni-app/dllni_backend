<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Delivery\Database\Seeders\MandoubDeliveryTestUserSeeder;
use Modules\Delivery\Database\Seeders\MandoubPrimaryOfferScenarioSeeder;
use Modules\Delivery\Enums\DeliveryAssignmentAttemptStatus;
use Modules\Delivery\Models\DeliveryAssignmentAttempt;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;

it('can reseed the Mandoub primary offer scenario without assignment attempt collisions', function (): void {
    $this->seed(MandoubDeliveryTestUserSeeder::class);
    $this->seed(MandoubPrimaryOfferScenarioSeeder::class);

    $this->seed(MandoubDeliveryTestUserSeeder::class);
    $this->seed(MandoubPrimaryOfferScenarioSeeder::class);

    $primaryUser = User::query()->where('email', 'mandoub.test@dllni.sy')->firstOrFail();
    $nearbyUser = User::query()->where('email', 'mandoub.nearby@dllni.sy')->firstOrFail();
    $primaryDriver = DeliveryDriver::query()->where('user_id', $primaryUser->id)->firstOrFail();
    $nearbyDriver = DeliveryDriver::query()->where('user_id', $nearbyUser->id)->firstOrFail();
    $activeOrder = DeliveryOrder::query()->where('order_number', 'DLV-MANDOUB-ACTIVE')->firstOrFail();

    expect((int) $activeOrder->driver_id)->toBe((int) $nearbyDriver->id)
        ->and(
            DeliveryAssignmentAttempt::query()
                ->where('order_id', $activeOrder->id)
                ->where('driver_id', $nearbyDriver->id)
                ->where('attempt_no', 1)
                ->where('status', DeliveryAssignmentAttemptStatus::Accepted->value)
                ->exists()
        )->toBeTrue()
        ->and(
            DeliveryAssignmentAttempt::query()
                ->where('order_id', $activeOrder->id)
                ->where('driver_id', $primaryDriver->id)
                ->where('attempt_no', 1)
                ->where('status', DeliveryAssignmentAttemptStatus::Cancelled->value)
                ->exists()
        )->toBeTrue();
});
