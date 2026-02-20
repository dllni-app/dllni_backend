<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisputeCategory;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Traits\FilterQueries\DisputeFilterQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class Dispute extends Model
{
    use DisputeFilterQuery;

    protected $fillable = [
        'booking_id',
        'booking_type',
        'ticket_number',
        'category',
        'status',
        'resolution',
    ];

    public function booking(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'booking_type', 'booking_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class);
    }

    protected function casts(): array
    {
        return [
            'category' => DisputeCategory::class,
            'status' => DisputeStatus::class,
            'resolution' => DisputeResolution::class,
        ];
    }
}
