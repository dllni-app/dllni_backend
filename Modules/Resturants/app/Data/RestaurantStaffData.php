<?php

declare(strict_types=1);

namespace Modules\Resturants\Data;

use Modules\Resturants\Models\RestaurantStaff;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<RestaurantStaff> */
final class RestaurantStaffData extends Data
{
    use HasModelAttributes;

    protected static string $model = RestaurantStaff::class;

    public function __construct(
        public ?int $restaurantId,
        public ?int $userId,
        public ?int $restaurantRoleId,
    ) {}
}
