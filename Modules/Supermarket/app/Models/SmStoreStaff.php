<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SmStoreStaff extends Model
{
    protected $table = 'sm_store_staff';

    protected $fillable = [
        'store_id',
        'user_id',
        'is_active',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
