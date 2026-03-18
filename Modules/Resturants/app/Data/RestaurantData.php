<?php

declare(strict_types=1);

namespace Modules\Resturants\Data;

use Illuminate\Http\UploadedFile;
use Modules\Resturants\Models\Restaurant;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<Restaurant> */
final class RestaurantData extends Data
{
    use HasModelAttributes;

    protected static string $model = Restaurant::class;

    /**
     * @param  array<int, UploadedFile>|null  $images
     */
    public function __construct(
        public ?int $userId,
        public ?string $name,
        public ?string $slug,
        public ?string $description,
        public ?string $address,
        public ?string $city,
        public ?string $district,
        public ?string $locationDetails,
        public ?float $latitude,
        public ?float $longitude,
        public ?string $phone,
        public ?string $whatsappNumber,
        public ?string $email,
        public ?string $instagramUsername,
        public ?string $facebookPageName,
        public ?float $averageRating,
        public ?int $totalReviews,
        public ?int $estimatedPreparationTime,
        public ?float $minimumOrderAmount,
        public ?string $priceRange,
        public ?int $reputationScore,
        public ?int $warningCount,
        public ?int $visibilityScore,
        public ?bool $manualVisibilityOverride,
        public ?bool $isActive,
        public ?bool $isFeatured,
        public ?bool $isTemporarilyClosed,
        public ?string $suspensionUntil,
        public ?UploadedFile $primaryImage = null,
    ) {}
}
