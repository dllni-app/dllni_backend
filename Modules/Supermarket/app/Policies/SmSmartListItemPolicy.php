<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmSmartListItem;

final class SmSmartListItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmSmartListItem $smSmartListItem): bool
    {
        return true;
    }
}
