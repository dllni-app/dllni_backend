<?php

declare(strict_types=1);

namespace Modules\Resturants\Policies;

use App\Models\User;
use Modules\Resturants\Models\Offer;

final class OfferPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Offer $offer): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Offer $offer): bool
    {
        return true;
    }

    public function delete(User $user, Offer $offer): bool
    {
        return true;
    }
}
