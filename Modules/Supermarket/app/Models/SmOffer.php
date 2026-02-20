<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SmOffer extends Model
{
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
