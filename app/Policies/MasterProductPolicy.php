<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MasterProduct;
use App\Models\User;

final class MasterProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MasterProduct $masterProduct): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MasterProduct $masterProduct): bool
    {
        return true;
    }

    public function delete(User $user, MasterProduct $masterProduct): bool
    {
        return true;
    }
}
