<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class SmCommissionRuleData extends Data
{
    public function __construct(
        #[Exists('sm_stores', 'id')]
        public ?int $storeId,
        #[Max(255)]
        public ?string $commissionType,
        #[Numeric, Min(0)]
        public ?float $value,
        #[Numeric, Min(0)]
        public ?float $minOrderAmount,
        #[Numeric, Min(0)]
        public ?float $maxCommissionAmount,
        #[Date]
        public ?string $startsAt,
        #[Date]
        public ?string $endsAt,
        #[BooleanType]
        public ?bool $isDefault,
        #[BooleanType]
        public ?bool $isActive,
    ) {}

    public function onlyModelAttributes(): array
    {
        return array_filter($this->toArray(), fn ($v) => $v !== null);
    }
}
