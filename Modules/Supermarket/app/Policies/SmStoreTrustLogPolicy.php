<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use App\Traits\AuthorizesByPermissionGroup;
use Modules\Supermarket\Models\SmStoreTrustLog;

final class SmStoreTrustLogPolicy
{
    use AuthorizesByPermissionGroup;

    public function viewAny(User $user): bool
    {
        return $this->authorizeAction($user, 'view');
    }

    public function view(User $user, SmStoreTrustLog $smStoreTrustLog): bool
    {
        return $this->authorizeAction($user, 'view');
    }
}
