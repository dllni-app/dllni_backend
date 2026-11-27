<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
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
        'parent_id',
        'is_active',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function scopeEffectivelyActive(Builder $query): Builder
    {
        return $query->where('categories.is_active', true)
            ->where(function (Builder $category): void {
                $category->whereNull('categories.parent_id')
                    ->orWhereExists(function ($parent): void {
                        $parent->selectRaw('1')->from('categories as menu_parent')
                            ->whereColumn('menu_parent.id', 'categories.parent_id')
                            ->whereColumn('menu_parent.restaurant_id', 'categories.restaurant_id')
                            ->whereNull('menu_parent.parent_id')
                            ->where('menu_parent.is_active', true);
                    });
            });
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('category-image')->singleFile();
    }

    public function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'parent_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function registerMediaConversions(?\Spatie\MediaLibrary\MediaCollections\Models\Media $media = null): void
    {
        \App\Support\ImageVariants::register($this, $media);
    }

}
