<?php

declare(strict_types=1);

namespace Modules\Cleaning\Data;

use Modules\Cleaning\Models\CleaningBooking;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<CleaningBooking> */
final class CleaningBookingData extends Data
{
    use HasModelAttributes;

    protected static string $model = CleaningBooking::class;

    public function __construct(
        public ?int $customerId,
        public ?int $workerId,
        public ?int $preferredWorkerId,
        public ?int $cancellationPolicyId,
        public ?int $billingPolicyId,
        public ?string $bookingNumber,
        public ?string $status,
        public ?string $propertyType,
        public ?array $propertyDetails,
        public ?float $estimatedSqm,
        public ?float $estimatedHours,
        public ?string $scheduledDate,
        public ?string $scheduledTime,
        public ?float $totalHours,
        public ?float $basePrice,
        public ?float $addonsTotal,
        public ?float $travelFee,
        public ?float $cancellationFee,
        public ?float $totalPrice,
        public ?bool $termsAccepted,
        public ?string $workStartedAt,
        public ?string $workFinishedAt,
        public ?string $startedTravelAt,
        public ?string $customerConfirmedAt,
        public ?string $cancelledAt,
        public ?string $cancellationReason,
    ) {}
}
