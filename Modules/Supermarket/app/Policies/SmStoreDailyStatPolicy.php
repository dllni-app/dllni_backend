<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmStoreDailyStat;

final class SmStoreDailyStatPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmStoreDailyStat $smStoreDailyStat): bool
    {
        return true;
    }
}
