<?php

declare(strict_types=1);

namespace Modules\User\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\MarketingOfferFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\User\Enums\MarketingOfferTheme;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string $discount_label
 * @property string|null $promo_code
 * @property CarbonInterface|null $starts_at
 * @property CarbonInterface|null $ends_at
 * @property MarketingOfferTheme $theme
 * @property int $sort_order
 * @property bool $is_active
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class MarketingOffer extends Model implements HasMedia
{
    /** @use HasFactory<MarketingOfferFactory> */
    use HasFactory;

    use InteractsWithMedia;

    public const IMAGE_COLLECTION = 'card-image';

    protected $fillable = [
        'title',
        'description',
        'discount_label',
        'promo_code',
        'starts_at',
        'ends_at',
        'theme',
        'sort_order',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'title' => 'string',
            'description' => 'string',
            'discount_label' => 'string',
            'promo_code' => 'string',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'theme' => MarketingOfferTheme::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<MarketingOffer>  $query
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::IMAGE_COLLECTION)
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function scopeCurrentlyValid(Builder $query): void
    {
        $now = CarbonImmutable::now();

        $query->where('is_active', true)
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }

    public function resolveRouteBinding($value, $field = null): self
    {
        $field ??= $this->getRouteKeyName();

        /** @var self|null $offer */
        $offer = self::query()
            ->currentlyValid()
            ->where($field, $value)
            ->first();

        if ($offer === null) {
            abort(404);
        }

        return $offer;
    }

    protected static function newFactory(): MarketingOfferFactory
    {
        return MarketingOfferFactory::new();
    }
}
