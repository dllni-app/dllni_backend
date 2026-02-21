<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class SmOrderDisputeData extends Data
{
    public function __construct(
        #[Exists('sm_orders', 'id')]
        public ?int $orderId,
        #[Exists('users', 'id')]
        public ?int $openedByUserId,
        #[Max(255)]
        public ?string $ticketNumber,
        #[In(['open', 'under_review', 'resolved', 'closed'])]
        public ?string $status,
        #[Max(255)]
        public ?string $reason,
        public ?string $description,
        #[Date]
        public ?string $resolvedAt,
        #[Exists('users', 'id')]
        public ?int $resolvedByUserId,
        public ?string $resolutionNotes,
    ) {}

    public function onlyModelAttributes(): array
    {
        return array_filter($this->toArray(), fn ($value) => $value !== null);
    }
}
