<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Supermarket\Traits\FilterQueries\SmCartFilterQuery;

final class SmCart extends Model
{
    use SmCartFilterQuery;

    protected $table = 'sm_carts';

    protected $fillable = [
        'user_id',
        'store_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SmCartItem::class, 'cart_id');
    }
}
