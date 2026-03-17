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
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function offerProducts(): HasMany
    {
        return $this->hasMany(SmOfferProduct::class, 'offer_id');
    }

    public function scopeWithAnalyticsCounts(Builder $query): Builder
    {
        return $query
            ->withCount('offerProducts')
            ->addSelect([
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

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
