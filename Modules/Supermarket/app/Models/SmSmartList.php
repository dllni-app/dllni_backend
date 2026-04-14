<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Supermarket\Traits\FilterQueries\SmSmartListFilterQuery;

final class SmSmartList extends Model
{
    use SmSmartListFilterQuery;

    protected $table = 'sm_smart_lists';

    protected $fillable = [
        'user_id',
        'store_id',
        'name',
        'description',
        'is_active',
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
        return $this->hasMany(SmSmartListItem::class, 'smart_list_id');
    }

    public function schedule(): HasOne
    {
        return $this->hasOne(SmSmartListSchedule::class, 'smart_list_id');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
