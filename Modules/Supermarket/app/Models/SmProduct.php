<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\MasterProduct;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Modules\Supermarket\Enums\SmProductSource;
use Modules\Supermarket\Traits\FilterQueries\SmProductFilterQuery;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class SmProduct extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use SmProductFilterQuery;

    public const IMAGE_COLLECTION = 'product-image';

    protected $table = 'sm_products';

    protected $fillable = [
        'store_id',
        'category_id',
        'master_product_id',
        'name',
        'barcode',
        'source_type',
        'description',
        'price',
        'discounted_price',
        'stock_quantity',
        'low_stock_threshold',
        'expires_at',
        'is_available',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(SmCategory::class, 'category_id');
    }

    public function masterProduct(): BelongsTo
    {
        return $this->belongsTo(MasterProduct::class, 'master_product_id');
    }

    public function inventoryLogs(): HasMany
    {
        return $this->hasMany(SmInventoryLog::class, 'product_id');
    }

    public function offerProducts(): HasMany
    {
        return $this->hasMany(SmOfferProduct::class, 'product_id');
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(SmModifierGroup::class, 'sm_modifier_group_product', 'product_id', 'modifier_group_id')
            ->withTimestamps();
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(SmCartItem::class, 'product_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(SmOrderItem::class, 'product_id');
    }

    /**
     * Price that should be charged now, considering the product's manual
     * discounted price and every active supermarket offer linked to it.
     */
    public function effectivePrice(): int
    {
        $basePrice = max(0, (int) ($this->attributes['price'] ?? 0));
        $bestPrice = $basePrice;

        $manualDiscount = $this->attributes['discounted_price'] ?? null;
        if ($manualDiscount !== null && is_numeric($manualDiscount)) {
            $bestPrice = min($bestPrice, max(0, (int) round((float) $manualDiscount)));
        }

        foreach ($this->activeOffersNow() as $offerProduct) {
            $offer = $offerProduct->offer;
            if (! $offer instanceof SmOffer) {
                continue;
            }

            $candidate = $basePrice;
            $type = strtolower(trim((string) $offer->offer_type));

            if ($type === 'percent') {
                $percent = min(100.0, max(0.0, (float) ($offer->discount_percent ?? 0)));
                $candidate = (int) round($basePrice * (1 - ($percent / 100)));
            } elseif ($type === 'value') {
                $discountValue = max(0.0, (float) ($offer->discount_value ?? 0));
                $candidate = (int) round(max(0.0, $basePrice - $discountValue));
            }

            $bestPrice = min($bestPrice, max(0, $candidate));
        }

        return $bestPrice;
    }

    /**
     * Return a discounted price only when a real discount exists. A value of
     * zero is intentional and represents a valid 100% discount.
     */
    public function getDiscountedPriceAttribute(mixed $value): ?int
    {
        $basePrice = max(0, (int) ($this->attributes['price'] ?? 0));
        $effectivePrice = $this->effectivePrice();

        return $effectivePrice < $basePrice ? $effectivePrice : null;
    }

    /**
     * @return Collection<int, SmOfferProduct>
     */
    public function activeOffersNow(): Collection
    {
        $offerProducts = $this->relationLoaded('offerProducts')
            ? $this->offerProducts->loadMissing('offer')
            : $this->offerProducts()->with('offer')->get();
        $now = now();

        return $offerProducts
            ->filter(static function (SmOfferProduct $offerProduct) use ($now): bool {
                $offer = $offerProduct->offer;
                if (! $offer instanceof SmOffer || ! $offer->is_active) {
                    return false;
                }
                if ($offer->starts_at !== null && $offer->starts_at->gt($now)) {
                    return false;
                }
                if ($offer->ends_at !== null && $offer->ends_at->lt($now)) {
                    return false;
                }

                return true;
            })
            ->values();
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

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'discounted_price' => 'integer',
            'source_type' => SmProductSource::class,
            'expires_at' => 'datetime',
            'is_available' => 'boolean',
        ];
    }
}
