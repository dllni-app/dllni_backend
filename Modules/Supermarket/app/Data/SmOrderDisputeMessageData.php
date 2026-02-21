<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class SmOrderDisputeMessageData extends Data
{
    public function __construct(
        #[Exists('sm_order_disputes', 'id')]
        public ?int $disputeId,
        #[Exists('users', 'id')]
        public ?int $userId,
        #[Max(5000)]
        public ?string $message,
        #[BooleanType]
        public ?bool $isInternal,
    ) {}

    public function onlyModelAttributes(): array
    {
        return array_filter($this->toArray(), fn ($value) => $value !== null);
    }
}
