<?php

declare(strict_types=1);

namespace Modules\Cleaning\Policies;

use App\Models\User;
use Modules\Cleaning\Models\EventBooking;

final class EventBookingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, EventBooking $eventBooking): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, EventBooking $eventBooking): bool
    {
        return true;
    }

    public function delete(User $user, EventBooking $eventBooking): bool
    {
        return true;
    }
}
