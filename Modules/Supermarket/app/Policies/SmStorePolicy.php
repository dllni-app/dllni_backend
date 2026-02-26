<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmStore;

final class SmStorePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmStore $model): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SmStore $model): bool
    {
        return true;
    }

    public function delete(User $user, SmStore $model): bool
    {
        return true;
    }
}
