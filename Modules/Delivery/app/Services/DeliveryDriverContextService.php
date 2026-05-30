<?php

declare(strict_types=1);

namespace Modules\Delivery\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Delivery\Models\DeliveryDriver;

final class DeliveryDriverContextService
{
    /** @throws AuthorizationException */
    public function driver(): DeliveryDriver
    {
        $user = request()->user();

        if (! $user) {
            throw new AuthorizationException('Unauthenticated.');
        }

        $driver = DeliveryDriver::query()->where('user_id', $user->id)->first();

        if (! $driver) {
            throw new AuthorizationException('No delivery driver profile found for this user.');
        }

        return $driver;
    }
}
