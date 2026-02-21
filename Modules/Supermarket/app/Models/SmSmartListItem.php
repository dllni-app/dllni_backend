<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\MasterProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Supermarket\Traits\FilterQueries\SmSmartListItemFilterQuery;

final class SmSmartListItem extends Model
{
    use SmSmartListItemFilterQuery;

    protected $table = 'sm_smart_list_items';

    protected $fillable = [
        'smart_list_id',
        'master_product_id',
        'quantity',
        'unit',
        'sort_order',
    ];

    public function smartList(): BelongsTo
    {
        return $this->belongsTo(SmSmartList::class, 'smart_list_id');
    }

    public function masterProduct(): BelongsTo
    {
        return $this->belongsTo(MasterProduct::class, 'master_product_id');
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
        ];
    }
}
