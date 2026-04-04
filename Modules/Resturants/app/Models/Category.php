<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Resturants\Traits\FilterQueries\CategoryFilterQuery;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class Category extends Model implements HasMedia
{
    use CategoryFilterQuery;
    use HasFactory;
    use InteractsWithMedia;

    protected $fillable = [
        'restaurant_id',
        'name',
        'slug',
        'sort_order',
    ];

    public static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('category-image')->singleFile();
    }

    public function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
