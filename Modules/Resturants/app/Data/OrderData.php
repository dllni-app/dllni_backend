<?php

declare(strict_types=1);

namespace Modules\Resturants\Data;

use Modules\Resturants\Models\Order;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<Order> */
final class OrderData extends Data
{
    use HasModelAttributes;

    protected static string $model = Order::class;

    public function __construct(
        public ?int $userId,
        public ?int $restaurantId,
        public ?int $promoCodeId,
        public ?int $assignedStaffId,
        public ?int $cancellationPolicyId,
        public ?string $orderNumber,
        public ?string $status,
        public ?string $orderType,
        public ?string $pickupMode,
        public ?string $pickupScheduledFor,
        public ?string $readyForPickupAt,
        public ?string $pickedUpAt,
        public ?string $customerPickupConfirmedAt,
        public ?float $subtotal,
        public ?float $discountAmount,
        public ?float $taxAmount,
        public ?float $serviceFee,
        public ?float $totalAmount,
        public ?float $cancellationFeeAmount,
        public ?array $cancellationPolicySnapshot,
        public ?string $specialInstructions,
        public ?string $acceptedAt,
        public ?string $preparingAt,
        public ?string $completedAt,
        public ?string $cancelledAt,
        public ?string $cancellationReason,
    ) {}
}
