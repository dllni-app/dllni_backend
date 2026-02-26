<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmInventoryLog;

final class SmInventoryLogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmInventoryLog $smInventoryLog): bool
    {
        return true;
    }
}
