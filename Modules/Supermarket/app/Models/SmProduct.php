<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\MasterProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Supermarket\Enums\SmProductSource;
use Modules\Supermarket\Traits\FilterQueries\SmProductFilterQuery;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class SmProduct extends Model implements HasMedia
{
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

    public function cartItems(): HasMany
    {
        return $this->hasMany(SmCartItem::class, 'product_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(SmOrderItem::class, 'product_id');
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
            'price' => 'decimal:2',
            'discounted_price' => 'decimal:2',
            'source_type' => SmProductSource::class,
            'expires_at' => 'datetime',
            'is_available' => 'boolean',
        ];
    }
}
