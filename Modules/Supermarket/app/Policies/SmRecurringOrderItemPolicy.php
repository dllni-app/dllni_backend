<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use App\Traits\AuthorizesByPermissionGroup;
use Modules\Supermarket\Models\SmRecurringOrderItem;

final class SmRecurringOrderItemPolicy
{
    use AuthorizesByPermissionGroup;

    public function viewAny(User $user): bool
    {
        return $this->authorizeAction($user, 'view');
    }

    public function view(User $user, SmRecurringOrderItem $smRecurringOrderItem): bool
    {
        return $this->authorizeAction($user, 'view');
    }
}
