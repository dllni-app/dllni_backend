<?php

declare(strict_types=1);

namespace Modules\Cleaning\Data;

use Modules\Cleaning\Models\CleaningService;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<CleaningService> */
final class CleaningServiceData extends Data
{
    use HasModelAttributes;

    protected static string $model = CleaningService::class;

    public function __construct(
        public ?string $name,
        public ?string $slug,
        public ?string $category,
        public ?string $description,
        public ?bool $isActive,
    ) {}
}
