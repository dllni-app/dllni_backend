<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmOrderItem;

final class SmOrderItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmOrderItem $smOrderItem): bool
    {
        return true;
    }
}
