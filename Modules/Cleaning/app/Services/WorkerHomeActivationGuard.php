<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\Worker;
use Illuminate\Validation\ValidationException;

/** Enforce the same home-location activation invariant across worker APIs. */
final class WorkerHomeActivationGuard
{
    /** @param array<string, mixed> $pending Worker model keys supplied by a profile edit. */
    public function assertCanActivate(Worker $worker, array $pending = []): void
    {
        $address = array_key_exists('home_address', $pending) ? $pending['home_address'] : $worker->home_address;
        $latitude = array_key_exists('home_latitude', $pending) ? $pending['home_latitude'] : $worker->home_latitude;
        $longitude = array_key_exists('home_longitude', $pending) ? $pending['home_longitude'] : $worker->home_longitude;

        if (
            ! is_string($address)
            || mb_trim($address) === ''
            || ! $this->validCoordinate($latitude, -90.0, 90.0)
            || ! $this->validCoordinate($longitude, -180.0, 180.0)
        ) {
            throw ValidationException::withMessages([
                'isActive' => ['Set a valid worker home address and location before activating your account.'],
            ]);
        }
    }

    private function validCoordinate(mixed $value, float $minimum, float $maximum): bool
    {
        return is_numeric($value)
            && is_finite((float) $value)
            && (float) $value >= $minimum
            && (float) $value <= $maximum;
    }
}
