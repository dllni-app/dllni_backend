<?php

declare(strict_types=1);

namespace Modules\Supermarket\Policies;

use App\Models\User;
use Modules\Supermarket\Models\SmOffer;

final class SmOfferPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SmOffer $smOffer): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, SmOffer $smOffer): bool
    {
        return true;
    }

    public function delete(User $user, SmOffer $smOffer): bool
    {
        return true;
    }
}
