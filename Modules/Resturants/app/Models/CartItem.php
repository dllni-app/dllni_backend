<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

    protected static function booted(): void
    {
        $bump = static function (self $item): void {
            if (Schema::hasColumn('carts', 'revision')) {
                DB::table('carts')->where('id', $item->cart_id)->increment('revision');
            }
        };
        self::created($bump);
        self::updated($bump);
        self::deleted($bump);
    }
}
