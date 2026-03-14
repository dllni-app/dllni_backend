<?php

declare(strict_types=1);

namespace Modules\Resturants\Support;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantStaff;

final class RestaurantOwnerContext
{
    private ?User $resolvedOwner = null;

    private ?Restaurant $resolvedRestaurant = null;

    /** @throws AuthorizationException */
    public function owner(): User
    {
        if ($this->resolvedOwner !== null) {
            return $this->resolvedOwner;
        }

        /** @var User|null $user */
        $user = auth()->user();

        if (! $user) {
            throw new AuthorizationException('Unauthenticated.');
        }

        if ($user->module_type !== UserModuleType::RestaurantSeller) {
            throw new AuthorizationException('This endpoint is for restaurant sellers only.');
        }

        return $this->resolvedOwner = $user;
    }

    /** @throws AuthorizationException */
    public function restaurant(): Restaurant
    {
        if ($this->resolvedRestaurant !== null) {
            return $this->resolvedRestaurant;
        }

        $owner = $this->owner();
        $restaurant = $owner->restaurants()->first();

        if (! $restaurant) {
            throw new AuthorizationException('No restaurant found for this owner.');
        }

        return $this->resolvedRestaurant = $restaurant;
    }

    /** @throws AuthorizationException */
    public function restaurantId(): int
    {
        return (int) $this->restaurant()->id;
    }

    /** @throws AuthorizationException */
    public function ensureOwnedOrder(Order $order): void
    {
        $restaurant = $this->restaurant();
        if ((int) $order->restaurant_id !== (int) $restaurant->id) {
            throw new AuthorizationException('You do not have access to this order.');
        }
    }

    /** @throws AuthorizationException */
    public function ensureOwnedProduct(Product $product): void
    {
        $restaurant = $this->restaurant();
        if ((int) $product->restaurant_id !== (int) $restaurant->id) {
            throw new AuthorizationException('You do not have access to this product.');
        }
    }

    /** @throws AuthorizationException */
    public function ensureOwnedStaff(RestaurantStaff $staff): void
    {
        $restaurant = $this->restaurant();
        if ((int) $staff->restaurant_id !== (int) $restaurant->id) {
            throw new AuthorizationException('You do not have access to this employee.');
        }
    }

    public function modelBelongsToRestaurant(Model $model, int $restaurantId): bool
    {
        return (int) ($model->getAttribute('restaurant_id') ?? 0) === $restaurantId;
    }
}
