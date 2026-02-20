<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmStore;
use Mrmarchone\LaravelAutoCrud\Traits\AuthorizesByPermissionGroup;

final class SmStorePolicy
{
    use AuthorizesByPermissionGroup;

    public function viewAny(User $user): bool
    {
        return $this->authorizeAction($user, 'view');
    }

    public function view(User $user, SmStore $model): bool
    {
        return $this->authorizeAction($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeAction($user, 'create');
    }

    public function update(User $user, SmStore $model): bool
    {
        return $this->authorizeAction($user, 'update');
    }

    public function delete(User $user, SmStore $model): bool
    {
        return $this->authorizeAction($user, 'delete');
    }
}
