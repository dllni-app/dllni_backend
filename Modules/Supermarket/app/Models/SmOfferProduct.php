<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SmOfferProduct extends Model
{
    protected $table = 'sm_offer_products';

    protected $fillable = [
        'offer_id',
        'product_id',
        'offer_price',
        'max_quantity',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(SmOffer::class, 'offer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SmProduct::class, 'product_id');
    }

    protected function casts(): array
    {
        return [
            'offer_price' => 'decimal:2',
        ];
    }
}
