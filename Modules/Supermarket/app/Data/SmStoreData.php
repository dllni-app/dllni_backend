<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Carbon\Carbon;
use Modules\Supermarket\Models\SmStore;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Email;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Data;

final class SmStoreData extends Data
{
    /** @var class-string<SmStore> */
    protected static string $model = SmStore::class;

    public function __construct(
        #[MapOutputName('owner_user_id'), Exists('users', 'id')]
        public ?int $ownerUserId,
        #[Max(255)]
        public ?string $name,
        #[Max(255)]
        public ?string $slug,
        public ?string $description,
        #[Max(255)]
        public ?string $address,
        #[MapOutputName('latitude'), Numeric]
        public ?float $latitude,
        #[MapOutputName('longitude'), Numeric]
        public ?float $longitude,
        #[Max(255)]
        public ?string $phone,
        #[Email, Max(255)]
        public ?string $email,
        #[MapOutputName('average_rating'), Numeric, Min(0)]
        public ?float $averageRating,
        #[MapOutputName('total_reviews'), Min(0)]
        public ?int $totalReviews,
        #[MapOutputName('trust_score'), Min(0)]
        public ?int $trustScore,
        #[MapOutputName('warning_count'), Min(0)]
        public ?int $warningCount,
        #[MapOutputName('is_active'), BooleanType]
        public ?bool $isActive,
        #[MapOutputName('is_featured'), BooleanType]
        public ?bool $isFeatured,
        #[MapOutputName('suspension_until'), Date]
        public ?Carbon $suspensionUntil,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function onlyModelAttributes(): array
    {
        return array_filter(
            $this->toArray(),
            static fn(mixed $value): bool => $value !== null
        );
    }
}
