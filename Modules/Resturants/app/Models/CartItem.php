<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_id',
        'substitute_product_id',
        'quantity',
        'unit_price',
        'total_price',
        'special_instructions',
        'signature_hash',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function modifiers(): BelongsToMany
    {
        return $this->belongsToMany(Modifier::class, 'cart_item_modifier')
            ->withPivot(['price'])
            ->withTimestamps();
    }
}
