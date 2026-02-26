<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmOrder;

final class SmOrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmOrder $smOrder): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SmOrder $smOrder): bool
    {
        return true;
    }

    public function delete(User $user, SmOrder $smOrder): bool
    {
        return true;
    }
}
