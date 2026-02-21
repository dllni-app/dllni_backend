<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use App\Traits\AuthorizesByPermissionGroup;
use Modules\Supermarket\Models\SmStoreDailyStat;

final class SmStoreDailyStatPolicy
{
    use AuthorizesByPermissionGroup;

    public function viewAny(User $user): bool
    {
        return $this->authorizeAction($user, 'view');
    }

    public function view(User $user, SmStoreDailyStat $smStoreDailyStat): bool
    {
        return $this->authorizeAction($user, 'view');
    }
}
