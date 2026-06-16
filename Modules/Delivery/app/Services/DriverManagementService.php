<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use DateTimeInterface;
use InvalidArgumentException;
use Modules\Delivery\Enums\DeliveryDriverAvailabilityStatus;
use Modules\Delivery\Enums\DeliverySuspensionReason;
use Modules\Delivery\Models\DeliveryDriver;

final class DriverManagementService
{
    public function suspend(DeliveryDriver $driver, string $reason, ?DateTimeInterface $until = null): DeliveryDriver
    {
        if ($driver->is_suspended) {
            throw new InvalidArgumentException('Driver is already suspended.');
        }

        $driver->forceFill([
            'is_suspended' => true,
            'suspension_reason' => $reason,
            'suspended_until' => $until,
            'availability_status' => DeliveryDriverAvailabilityStatus::Offline->value,
        ])->save();

        return $driver->fresh();
    }

    public function unsuspend(DeliveryDriver $driver): DeliveryDriver
    {
        if (! $driver->is_suspended) {
            throw new InvalidArgumentException('Driver is not suspended.');
        }

        if ($driver->suspension_reason === DeliverySuspensionReason::Financial->value) {
            throw new InvalidArgumentException('Financial suspensions can only be cleared after the company balance is below the limit.');
        }

        $attributes = [
            'is_suspended' => false,
            'suspension_reason' => null,
            'suspended_until' => null,
        ];

        if ($driver->is_active) {
            $attributes['availability_status'] = DeliveryDriverAvailabilityStatus::Offline->value;
        }

        $driver->forceFill($attributes)->save();

        return $driver->fresh();
    }

    public function activate(DeliveryDriver $driver): DeliveryDriver
    {
        if ($driver->is_active) {
            throw new InvalidArgumentException('Driver is already active.');
        }

        $driver->forceFill([
            'is_active' => true,
            'availability_status' => $driver->is_suspended
                ? DeliveryDriverAvailabilityStatus::Offline->value
                : DeliveryDriverAvailabilityStatus::Offline->value,
        ])->save();

        return $driver->fresh();
    }

    public function deactivate(DeliveryDriver $driver): DeliveryDriver
    {
        if (! $driver->is_active) {
            throw new InvalidArgumentException('Driver is already inactive.');
        }

        $driver->forceFill([
            'is_active' => false,
            'availability_status' => DeliveryDriverAvailabilityStatus::Offline->value,
        ])->save();

        return $driver->fresh();
    }
}
