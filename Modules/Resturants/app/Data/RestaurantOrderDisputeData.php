<?php

declare(strict_types=1);

namespace Modules\Resturants\Data;

use Modules\Resturants\Models\RestaurantOrderDispute;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<RestaurantOrderDispute> */
final class RestaurantOrderDisputeData extends Data
{
    use HasModelAttributes;

    protected static string $model = RestaurantOrderDispute::class;

    public function __construct(
        public ?int $orderId,
        public ?int $userId,
        public ?string $ticketNumber,
        public ?string $status,
        public ?string $description,
    ) {}
}
