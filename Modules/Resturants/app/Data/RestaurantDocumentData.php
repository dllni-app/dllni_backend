<?php

declare(strict_types=1);

namespace Modules\Resturants\Data;

use Modules\Resturants\Models\RestaurantDocument;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<RestaurantDocument> */
final class RestaurantDocumentData extends Data
{
    use HasModelAttributes;

    protected static string $model = RestaurantDocument::class;

    public function __construct(
        public ?int $restaurantId,
        public ?string $documentType,
        public ?string $verificationStatus,
        public ?string $filePath,
    ) {}
}
