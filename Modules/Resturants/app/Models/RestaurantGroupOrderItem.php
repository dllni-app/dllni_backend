<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\Resturants\Models\RestaurantGroupOrderParticipant;

final class RestaurantGroupOrderItem extends Model
{
    protected $fillable = [
        'group_order_id',
        'participant_id',
        'product_id',
        'substitute_product_id',
        'quantity',
        'unit_price',
        'total_price',
        'special_instructions',
    ];

    public function groupOrder(): BelongsTo
    {
        return $this->belongsTo(RestaurantGroupOrder::class, 'group_order_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(RestaurantGroupOrderParticipant::class, 'participant_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function substituteProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'substitute_product_id');
    }

    public function modifiers(): BelongsToMany
    {
        return $this->belongsToMany(Modifier::class, 'restaurant_group_order_item_modifier', 'group_order_item_id', 'modifier_id')
            ->withPivot('price')
            ->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
        ];
    }
}
