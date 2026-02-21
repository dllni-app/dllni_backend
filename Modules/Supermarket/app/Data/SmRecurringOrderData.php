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
final class SmRecurringOrderData extends Data
{
    public function __construct(
        #[Exists('users', 'id')]
        public ?int $userId,
        #[Exists('sm_stores', 'id')]
        public ?int $storeId,
        #[In(['active', 'paused', 'cancelled'])]
        public ?string $status,
        #[Max(255)]
        public ?string $frequency,
        public ?array $frequencyConfig,
        #[Date]
        public ?string $nextRunAt,
        #[Date]
        public ?string $lastRunAt,
        #[Date]
        public ?string $pausedAt,
    ) {}

    public function onlyModelAttributes(): array
    {
        return array_filter($this->toArray(), fn ($value) => $value !== null);
    }
}
