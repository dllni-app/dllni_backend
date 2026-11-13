<?php

declare(strict_types=1);

namespace Modules\Resturants\Policies;

use App\Enums\UserModuleType;
use App\Models\User;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\RestaurantStaff;

final class OfferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->module_type === UserModuleType::RestaurantSeller;
    }

    public function view(User $user, Offer $offer): bool
    {
        return $this->owns($user, $offer);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Offer $offer): bool
    {
        return $this->owns($user, $offer);
    }

    public function delete(User $user, Offer $offer): bool
    {
        return $this->owns($user, $offer);
    }

    private function owns(User $user, Offer $offer): bool
    {
        return $this->viewAny($user) && (
            $user->restaurants()->whereKey($offer->restaurant_id)->exists()
            || RestaurantStaff::query()->where('user_id', $user->id)
                ->where('restaurant_id', $offer->restaurant_id)->where('is_active', true)->exists()
        );
    }
}
