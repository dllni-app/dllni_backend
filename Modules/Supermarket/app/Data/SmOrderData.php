<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class SmOrderData extends Data
{
    public function __construct(
        #[Exists('users', 'id')]
        public ?int $customerId,
        #[Exists('sm_stores', 'id')]
        public ?int $storeId,
        #[Exists('sm_coupons', 'id')]
        public ?int $couponId,
        #[Exists('cancellation_policies', 'id')]
        public ?int $cancellationPolicyId,
        #[Max(255)]
        public ?string $orderNumber,
        #[In(['pending', 'accepted', 'preparing', 'ready_for_pickup', 'completed', 'cancelled'])]
        public ?string $status,
        #[In(['immediate_pickup', 'scheduled_pickup'])]
        public ?string $pickupMode,
        #[Date]
        public ?string $pickupScheduledFor,
        #[Date]
        public ?string $readyForPickupAt,
        #[Date]
        public ?string $pickedUpAt,
        #[Date]
        public ?string $customerPickupConfirmedAt,
        #[Numeric, Min(0)]
        public ?float $subtotal,
        #[Numeric, Min(0)]
        public ?float $discountAmount,
        #[Numeric, Min(0)]
        public ?float $serviceFee,
        #[Numeric, Min(0)]
        public ?float $totalAmount,
        #[Numeric, Min(0)]
        public ?float $cancellationFeeAmount,
        public ?array $cancellationPolicySnapshot,
        public ?string $specialInstructions,
        #[Date]
        public ?string $cancelledAt,
        public ?string $cancellationReason,
    ) {}

    public function onlyModelAttributes(): array
    {
        return array_filter($this->toArray(), fn ($value) => $value !== null);
    }
}
