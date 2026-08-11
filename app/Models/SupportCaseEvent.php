<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupportCaseEvent extends Model
{
    protected $fillable = [
        'support_case_id',
        'actor_id',
        'event_type',
        'from_status',
        'to_status',
        'metadata',
    ];

    public function supportCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
