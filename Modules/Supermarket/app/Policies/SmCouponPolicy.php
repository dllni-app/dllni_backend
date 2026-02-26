<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmCoupon;

final class SmCouponPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmCoupon $smCoupon): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SmCoupon $smCoupon): bool
    {
        return true;
    }

    public function delete(User $user, SmCoupon $smCoupon): bool
    {
        return true;
    }
}
