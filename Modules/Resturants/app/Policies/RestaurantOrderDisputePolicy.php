<?php

declare(strict_types=1);

namespace Modules\Resturants\Policies;

use App\Models\User;
use Modules\Resturants\Models\RestaurantOrderDispute;

final class RestaurantOrderDisputePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RestaurantOrderDispute $dispute): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RestaurantOrderDispute $dispute): bool
    {
        return true;
    }

    public function delete(User $user, RestaurantOrderDispute $dispute): bool
    {
        return true;
    }
}
