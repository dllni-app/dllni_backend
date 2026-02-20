<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SmCategory extends Model
{
    protected $table = 'sm_categories';

    protected $fillable = [
        'store_id',
        'name',
        'slug',
        'description',
        'sort_order',
        'image_path',
        'is_active',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(SmProduct::class, 'category_id');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
