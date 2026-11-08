<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Supermarket\Traits\FilterQueries\SmCartItemFilterQuery;

final class SmCartItem extends Model
{
    use SmCartItemFilterQuery;

    protected $table = 'sm_cart_items';

    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'unit_price',
        'modifier_ids',
        'substitute_product_id',
        'note',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(SmCart::class, 'cart_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(SmProduct::class, 'product_id');
    }

    protected static function booted(): void
    {
        $bump = static function (self $item): void {
            if (Schema::hasColumn('sm_carts', 'revision')) {
                DB::table('sm_carts')->where('id', $item->cart_id)->increment('revision');
            }
        };
        self::created($bump);
        self::updated($bump);
        self::deleted($bump);
    }

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'modifier_ids' => 'array',
            'substitute_product_id' => 'integer',
        ];
    }
}
