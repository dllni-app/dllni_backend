<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmStoreHours;

final class SmStoreHoursPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmStoreHours $smStoreHours): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SmStoreHours $smStoreHours): bool
    {
        return true;
    }

    public function delete(User $user, SmStoreHours $smStoreHours): bool
    {
        return true;
    }
}
