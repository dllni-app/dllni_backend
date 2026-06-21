<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Modules\Cleaning\Support\CleaningNeighborhoodNameNormalizer;

/**
 * @extends Factory<CleaningNeighborhood>
 */
final class CleaningNeighborhoodFactory extends Factory
{
    protected $model = CleaningNeighborhood::class;

    public function definition(): array
    {
        static $sequence = 1;

        $index = $sequence++;
        $englishName = sprintf('Neighborhood %03d', $index);
        $arabicName = sprintf('حي %03d', $index);

        return [
            'city_name' => CleaningNeighborhoodNameNormalizer::ALEPPO_CITY,
            'name_ar' => $arabicName,
            'name_en' => $englishName,
            'normalized_name' => $arabicName,
            'aliases' => [$englishName],
            'center_latitude' => null,
            'center_longitude' => null,
            'sort_order' => $index,
            'is_active' => true,
        ];
    }
}
