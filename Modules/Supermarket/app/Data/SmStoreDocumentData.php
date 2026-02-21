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
final class SmStoreDocumentData extends Data
{
    public function __construct(
        #[Exists('sm_stores', 'id')]
        public ?int $storeId,
        #[In(['identity', 'commercial_registration', 'health_certificate', 'other'])]
        public ?string $documentType,
        #[Max(255)]
        public ?string $filePath,
        #[In(['pending', 'approved', 'rejected'])]
        public ?string $verificationStatus,
        public ?string $rejectionReason,
        #[Exists('users', 'id')]
        public ?int $verifiedByUserId,
        #[Date]
        public ?string $verifiedAt,
        #[Date]
        public ?string $expiresAt,
    ) {}

    public function onlyModelAttributes(): array
    {
        return array_filter($this->toArray(), fn ($value) => $value !== null);
    }
}
