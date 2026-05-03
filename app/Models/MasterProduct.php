<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MasterProductUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class MasterProduct extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const IMAGE_COLLECTION = 'master-product-image';

    protected $fillable = [
        'name',
        'barcode',
        'unit',
        'brand',
        'description',
        'is_active',
        'openfoodfacts_url',
        'openfoodfacts_last_modified_at',
        'openfoodfacts_imported_at',
        'openfoodfacts_payload_hash',
        'openfoodfacts_countries_tags',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(MasterProductAlias::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGE_COLLECTION)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->width(300)
            ->height(300)
            ->sharpen(10);
    }

    public function casts(): array
    {
        return [
            'unit' => MasterProductUnit::class,
            'is_active' => 'boolean',
            'openfoodfacts_last_modified_at' => 'datetime',
            'openfoodfacts_imported_at' => 'datetime',
            'openfoodfacts_countries_tags' => 'array',
        ];
    }
}
