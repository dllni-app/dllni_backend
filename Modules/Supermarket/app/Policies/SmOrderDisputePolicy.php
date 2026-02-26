<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmOrderDispute;

final class SmOrderDisputePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmOrderDispute $smOrderDispute): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SmOrderDispute $smOrderDispute): bool
    {
        return true;
    }

    public function delete(User $user, SmOrderDispute $smOrderDispute): bool
    {
        return true;
    }
}
