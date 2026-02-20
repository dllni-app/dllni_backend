<?php

declare(strict_types=1);

namespace Modules\Cleaning\Data;

use Modules\Cleaning\Models\EventBooking;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<EventBooking> */
final class EventBookingData extends Data
{
    use HasModelAttributes;

    protected static string $model = EventBooking::class;

    public function __construct(
        public ?int $customerId,
        public ?int $cancellationPolicyId,
        public ?int $billingPolicyId,
        public ?string $bookingNumber,
        public ?string $status,
        public ?string $eventType,
        public ?int $guestCountMin,
        public ?int $guestCountMax,
        public ?string $genderPreference,
        public ?int $suggestedTeamSize,
        public ?string $scheduledDate,
        public ?string $scheduledTime,
        public ?float $totalHours,
        public ?float $basePrice,
        public ?float $travelFee,
        public ?float $totalPrice,
        public ?bool $termsAccepted,
        public ?string $cancelledAt,
    ) {}
}
