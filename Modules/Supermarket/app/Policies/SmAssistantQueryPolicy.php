<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmAssistantQuery;

final class SmAssistantQueryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmAssistantQuery $smAssistantQuery): bool
    {
        return true;
    }
}
