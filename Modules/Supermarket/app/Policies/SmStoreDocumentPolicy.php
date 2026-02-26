<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmStoreDocument;

final class SmStoreDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmStoreDocument $smStoreDocument): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SmStoreDocument $smStoreDocument): bool
    {
        return true;
    }

    public function delete(User $user, SmStoreDocument $smStoreDocument): bool
    {
        return true;
    }
}
