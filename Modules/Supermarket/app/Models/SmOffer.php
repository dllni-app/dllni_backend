<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\JoinClause;
use Modules\Supermarket\Traits\FilterQueries\SmOfferFilterQuery;

final class SmOffer extends Model
{
    use SmOfferFilterQuery;

    protected $table = 'sm_offers';

    protected $fillable = [
        'store_id',
        'name',
        'description',
        'offer_type',
        'discount_value',
        'discount_percent',
        'starts_at',
        'ends_at',
        'is_active',
        'promotion_type',
        'buy_quantity',
        'reward_quantity',
        'reward_type',
        'reward_discount_percent',
        'is_repeatable',
        'max_applications_per_order',
        'rule_version',
        'show_upcoming',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function offerProducts(): HasMany
    {
        return $this->hasMany(SmOfferProduct::class, 'offer_id');
    }

    public function rewardProducts(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(SmProduct::class, 'sm_offer_reward_products', 'offer_id', 'product_id');
    }

    public function promotionAttributes(): array
    {
        return ['startsAtIso' => $this->starts_at?->toIso8601String(), 'endsAtIso' => $this->ends_at?->toIso8601String(), 'promotionType' => $this->promotion_type ?? 'product_discount',
            'buyQuantity' => $this->buy_quantity, 'rewardQuantity' => $this->reward_quantity,
            'rewardType' => $this->reward_type, 'rewardDiscountPercent' => $this->reward_discount_percent,
            'isRepeatable' => (bool) $this->is_repeatable, 'maxApplicationsPerOrder' => $this->max_applications_per_order,
            'ruleVersion' => (int) ($this->rule_version ?? 1), 'showUpcoming' => (bool) $this->show_upcoming,
            'status' => ! $this->is_active ? 'paused' : ($this->ends_at?->lte(now()) ? 'expired' : ($this->starts_at?->gt(now()) ? 'scheduled' : 'active'))];
    }

    public function scopeWithAnalyticsCounts(Builder $query): Builder
    {
        return $query
            ->withCount('offerProducts')
            ->addSelect([
                'promotion_orders_count' => \Illuminate\Support\Facades\DB::table('sm_order_promotion_applications')->selectRaw('COUNT(DISTINCT order_id)')->whereColumn('original_offer_id', 'sm_offers.id')->whereColumn('store_id', 'sm_offers.store_id')->where('status', 'applied'),
                'affected_orders_count' => SmOrder::query()
                    ->selectRaw('COUNT(DISTINCT sm_orders.id)')
                    ->join('sm_order_items', 'sm_order_items.order_id', '=', 'sm_orders.id')
                    ->join('sm_offer_products', static function (JoinClause $join): void {
                        $join->on('sm_offer_products.product_id', '=', 'sm_order_items.product_id')
                            ->whereColumn('sm_offer_products.offer_id', 'sm_offers.id');
                    })
                    ->whereColumn('sm_orders.store_id', 'sm_offers.store_id')
                    ->where(static function (Builder $query): void {
                        $query->whereNull('sm_offers.starts_at')
                            ->orWhereColumn('sm_orders.created_at', '>=', 'sm_offers.starts_at');
                    })
                    ->where(static function (Builder $query): void {
                        $query->whereNull('sm_offers.ends_at')
                            ->orWhereColumn('sm_orders.created_at', '<=', 'sm_offers.ends_at');
                    }),
            ]);
    }

    protected static function booted(): void
    {
        self::updating(function (self $offer): void {
            if ($offer->isDirty() && ! $offer->isDirty('rule_version')) {
                $offer->rule_version = (int) $offer->rule_version + 1;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'is_repeatable' => 'boolean', 'show_upcoming' => 'boolean', 'reward_discount_percent' => 'decimal:2',
        ];
    }
}
