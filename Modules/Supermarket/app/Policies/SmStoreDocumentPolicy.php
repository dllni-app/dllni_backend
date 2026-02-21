<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use App\Traits\AuthorizesByPermissionGroup;
use Modules\Supermarket\Models\SmStoreDocument;

final class SmStoreDocumentPolicy
{
    use AuthorizesByPermissionGroup;

    public function viewAny(User $user): bool
    {
        return $this->authorizeAction($user, 'view');
    }

    public function view(User $user, SmStoreDocument $smStoreDocument): bool
    {
        return $this->authorizeAction($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->authorizeAction($user, 'create');
    }

    public function update(User $user, SmStoreDocument $smStoreDocument): bool
    {
        return $this->authorizeAction($user, 'update');
    }

    public function delete(User $user, SmStoreDocument $smStoreDocument): bool
    {
        return $this->authorizeAction($user, 'delete');
    }
}
