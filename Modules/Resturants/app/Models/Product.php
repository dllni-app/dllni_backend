<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Carbon\CarbonInterface;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Traits\FilterQueries\ProductFilterQuery;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

final class Product extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use ProductFilterQuery;

    protected $fillable = [
        'restaurant_id',
        'category_id',
        'item_type',
        'product_kind',
        'catalog_role',
        'classification_status',
        'primary_food_category_id',
        'classified_at',
        'name',
        'description',
        'search_tags',
        'servings',
        'ingredient_exclusions',
        'price',
        'discounted_price',
        'is_available',
        'unavailable_until',
        'availability_note',
        'stock_quantity',
        'low_stock_threshold',
        'preparation_time',
        'is_featured',
    ];

    public function setIngredientExclusionsAttribute(?array $values): void
    {
        $terms = array_values(array_unique(array_filter(array_map(
            fn ($term) => \Modules\User\Services\RestaurantFoodSearchNormalizer::normalize((string) $term),
            $values ?? [],
        ))));
        $this->attributes['ingredient_exclusions'] = $values === null ? null : json_encode($terms, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function foodCategory(): BelongsTo
    {
        return $this->belongsTo(FoodDiscoveryCategory::class, 'primary_food_category_id');
    }

    public function scopeIndependentlyOrderable(Builder $query): Builder
    {
        return $query->where('catalog_role', 'independent');
    }

    public function scopeCuratedMeals(Builder $query): Builder
    {
        return $query->independentlyOrderable()
            ->where('classification_status', 'reviewed')
            ->whereIn('product_kind', ['main_dish', 'combo_meal']);
    }

    public function isIndependentlyOrderable(): bool
    {
        return ($this->catalog_role ?? 'independent') === 'independent';
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'modifier_group_product')
            ->withTimestamps();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function substitutions(): HasMany
    {
        return $this->hasMany(RestaurantProductSubstitution::class, 'product_id');
    }

    public function inventoryItems(): BelongsToMany
    {
        return $this->belongsToMany(InventoryItem::class, 'inventory_item_product')
            ->withPivot('quantity_used')
            ->withTimestamps();
    }

    public function offers(): BelongsToMany
    {
        return $this->belongsToMany(Offer::class, 'offer_product')
            ->withTimestamps();
    }

    /**
     * Return the price the customer must actually pay right now.
     *
     * The best price wins between the product-level discounted_price and all
     * currently active offers attached to the product. Percentage offers are
     * capped at 100% and fixed discounts never make the price negative.
     */
    public function effectivePrice(?CarbonInterface $at = null): float
    {
        $originalPrice = max(0.0, (float) ($this->price ?? 0));
        $bestPrice = $originalPrice;

        if ($this->discounted_price !== null) {
            $bestPrice = min($bestPrice, max(0.0, (float) $this->discounted_price));
        }

        foreach ($this->activeOffersNow($at) as $offer) {
            if (! $offer->isProductDiscount()) {
                continue;
            }
            $discountValue = max(0.0, (float) $offer->discount_value);
            $candidate = match ($offer->discount_type) {
                DiscountType::Percentage => $originalPrice * (1 - min(100.0, $discountValue) / 100),
                DiscountType::FixedAmount => $originalPrice - $discountValue,
            };

            $bestPrice = min($bestPrice, max(0.0, $candidate));
        }

        return round($bestPrice, 2);
    }

    public function effectiveDiscountedPrice(): ?float
    {
        $originalPrice = max(0.0, (float) ($this->price ?? 0));
        $effectivePrice = $this->effectivePrice();

        return $effectivePrice < $originalPrice ? $effectivePrice : null;
    }

    /** @return Collection<int, Offer> */
    public function activeOffersNow(?CarbonInterface $at = null): Collection
    {
        $offers = $this->relationLoaded('offers')
            ? $this->offers
            : $this->offers()->get();

        $now = $at ?? now();

        return $offers
            ->filter(static fn (Offer $offer): bool => $offer->isProductDiscount())
            ->filter(static fn (Offer $offer): bool => (bool) $offer->is_active)
            ->filter(static fn (Offer $offer): bool => $offer->starts_at === null || $offer->starts_at->lessThanOrEqualTo($now))
            ->filter(static fn (Offer $offer): bool => $offer->ends_at === null || $offer->ends_at->greaterThan($now))
            ->values();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('primary-image')->singleFile();
        $this->addMediaCollection('images');
    }

    public function scopeLowStock($query, mixed $value = true)
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
    }

    public function isAvailableNow(): bool
    {
        if ($this->is_available) {
            return true;
        }

        return $this->unavailable_until !== null && now()->greaterThan($this->unavailable_until);
    }

    public function availabilityMode(): string
    {
        if ($this->isAvailableNow()) {
            return 'available';
        }

        if ($this->unavailable_until !== null && now()->lessThanOrEqualTo($this->unavailable_until)) {
            return 'sold_out_today';
        }

        return 'manual_unavailable';
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected function casts(): array
    {
        return [
            'item_type' => 'string',
            'product_kind' => 'string',
            'catalog_role' => 'string',
            'classification_status' => 'string',
            'primary_food_category_id' => 'integer',
            'classified_at' => 'datetime',
            'search_tags' => 'array',
            'servings' => 'integer',
            'ingredient_exclusions' => 'array',
            'complementary_product_ids' => 'array',
            'price' => 'decimal:2',
            'discounted_price' => 'decimal:2',
            'is_available' => 'boolean',
            'unavailable_until' => 'datetime',
            'availability_note' => 'string',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
            'preparation_time' => 'integer',
            'is_featured' => 'boolean',
        ];
    }

    public function registerMediaConversions(?\Spatie\MediaLibrary\MediaCollections\Models\Media $media = null): void
    {
        \App\Support\ImageVariants::register($this, $media);
    }

}