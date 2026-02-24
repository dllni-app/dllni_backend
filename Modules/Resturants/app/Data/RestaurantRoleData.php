<?php

declare(strict_types=1);

namespace Modules\Resturants\Data;

use Modules\Resturants\Models\RestaurantRole;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<RestaurantRole> */
final class RestaurantRoleData extends Data
{
    use HasModelAttributes;

    protected static string $model = RestaurantRole::class;

    public function __construct(
        public ?int $restaurantId,
        public ?string $name,
        public ?string $slug,
    ) {}
}
