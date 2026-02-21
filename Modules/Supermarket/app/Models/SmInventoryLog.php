<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Supermarket\Traits\FilterQueries\SmInventoryLogFilterQuery;

final class SmInventoryLog extends Model
{
    use SmInventoryLogFilterQuery;

    protected $table = 'sm_inventory_logs';

    protected $fillable = [
        'product_id',
        'type',
        'quantity_change',
        'quantity_after',
        'reference_type',
        'reference_id',
        'notes',
        'user_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(SmProduct::class, 'product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
