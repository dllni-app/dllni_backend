<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class SmCategoryData extends Data
{
    public function __construct(
        #[Exists('sm_stores', 'id')]
        public ?int $storeId,
        #[Max(255)]
        public ?string $name,
        #[Max(255)]
        public ?string $slug,
        public ?string $description,
        #[Numeric, Min(0)]
        public ?int $sortOrder,
        #[Max(255)]
        public ?string $imagePath,
        #[BooleanType]
        public ?bool $isActive,
    ) {}

    public function onlyModelAttributes(): array
    {
        return array_filter($this->toArray(), fn ($v) => $v !== null);
    }
}
