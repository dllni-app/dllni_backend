<?php

declare(strict_types=1);

namespace Modules\Cleaning\Policies;

use App\Models\User;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CleaningBooking $cleaningBooking): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, CleaningBooking $cleaningBooking): bool
    {
        return true;
    }

    public function delete(User $user, CleaningBooking $cleaningBooking): bool
    {
        return true;
    }
}
