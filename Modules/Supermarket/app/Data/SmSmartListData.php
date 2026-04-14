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
final class SmSmartListData extends Data
{
    public function __construct(
        #[Exists('users', 'id')]
        public ?int $userId,
        #[Exists('sm_stores', 'id')]
        public ?int $storeId,
        #[Max(255)]
        public ?string $name,
        public ?string $description,
        #[BooleanType]
        public ?bool $isActive,
        public ?array $schedule,
    ) {}

    public function onlyModelAttributes(): array
    {
        return array_filter([
            'user_id' => $this->userId,
            'store_id' => $this->storeId,
            'name' => $this->name,
            'description' => $this->description,
            'is_active' => $this->isActive,
        ], static fn ($value) => $value !== null);
    }
}
