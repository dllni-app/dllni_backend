<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\WorkerZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Cleaning\Support\CleaningNeighborhoodNameNormalizer;
use Modules\User\Models\UserAddress;

final class CleaningNeighborhood extends Model
{
    use HasFactory;

    protected $fillable = [
        'city_name',
        'name_ar',
        'name_en',
        'normalized_name',
        'aliases',
        'center_latitude',
        'center_longitude',
        'sort_order',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $neighborhood): void {
            $neighborhood->name_ar = CleaningNeighborhoodNameNormalizer::repairText($neighborhood->name_ar);
            $neighborhood->name_en = CleaningNeighborhoodNameNormalizer::repairText($neighborhood->name_en);

            $sourceName = $neighborhood->name_ar ?: $neighborhood->name_en ?: '';
            $neighborhood->normalized_name = CleaningNeighborhoodNameNormalizer::normalize($sourceName);
            $neighborhood->city_name = CleaningNeighborhoodNameNormalizer::canonicalCityName($neighborhood->city_name)
                ?? CleaningNeighborhoodNameNormalizer::ALEPPO_CITY;
            $neighborhood->aliases = CleaningNeighborhoodNameNormalizer::normalizeAliases(
                is_array($neighborhood->aliases) ? $neighborhood->aliases : null
            );
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function workerZones(): HasMany
    {
        return $this->hasMany(WorkerZone::class, 'neighborhood_id');
    }

    public function userAddresses(): HasMany
    {
        return $this->hasMany(UserAddress::class, 'neighborhood_id');
    }

    public function cleaningBookings(): HasMany
    {
        return $this->hasMany(CleaningBooking::class, 'neighborhood_id');
    }

    public function casts(): array
    {
        return [
            'aliases' => 'array',
            'center_latitude' => 'decimal:7',
            'center_longitude' => 'decimal:7',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory()
    {
        return \Database\Factories\CleaningNeighborhoodFactory::new();
    }
}
