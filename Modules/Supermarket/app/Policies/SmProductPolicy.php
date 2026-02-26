<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmProduct;

final class SmProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmProduct $smProduct): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SmProduct $smProduct): bool
    {
        return true;
    }

    public function delete(User $user, SmProduct $smProduct): bool
    {
        return true;
    }
}
