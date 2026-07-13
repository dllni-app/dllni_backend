<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\Dispute;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<Dispute> */
final class DisputeData extends Data
{
    use HasModelAttributes;

    protected static string $model = Dispute::class;

    public function __construct(
        public ?int $bookingId,
        public ?string $bookingType,
        public ?string $ticketNumber,
        public ?string $description,
        public ?string $category,
        public ?string $status,
        public ?string $resolution,
    ) {}
}
