<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmCategory;

final class SmCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmCategory $smCategory): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SmCategory $smCategory): bool
    {
        return true;
    }

    public function delete(User $user, SmCategory $smCategory): bool
    {
        return true;
    }
}
