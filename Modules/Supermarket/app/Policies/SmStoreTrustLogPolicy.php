<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmStoreTrustLog;

final class SmStoreTrustLogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmStoreTrustLog $smStoreTrustLog): bool
    {
        return true;
    }
}
