<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class SmStoreHoursData extends Data
{
    public function __construct(
        #[Exists('sm_stores', 'id')]
        public ?int $storeId,
        #[Min(0), Max(6)]
        public ?int $dayOfWeek,
        public ?string $opensAt,
        public ?string $closesAt,
        #[BooleanType]
        public ?bool $isClosed,
    ) {}

    public function onlyModelAttributes(): array
    {
        $attributes = array_filter($this->toArray(), fn ($value) => $value !== null);

        if ($this->isClosed !== null) {
            $attributes['is_closed'] = $this->isClosed;
        }

        return $attributes;
    }
}
