<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class FoodDiscoveryCategory extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'food_discovery_categories';

    protected $fillable = [
        'slug',
        'name_ar',
        'name_en',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('category-image')->singleFile();
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'primary_food_category_id');
    }

    public function localizedName(string $locale = 'ar'): string
    {
        return $locale === 'en' && $this->name_en !== null
            ? $this->name_en
            : $this->name_ar;
    }

    public function registerMediaConversions(?\Spatie\MediaLibrary\MediaCollections\Models\Media $media = null): void
    {
        \App\Support\ImageVariants::register($this, $media);
    }

}
