<?php

declare(strict_types=1);

namespace Modules\Resturants\Policies;

use App\Models\User;
use Modules\Resturants\Models\RestaurantDocument;

final class RestaurantDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RestaurantDocument $document): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RestaurantDocument $document): bool
    {
        return true;
    }

    public function delete(User $user, RestaurantDocument $document): bool
    {
        return true;
    }
}
