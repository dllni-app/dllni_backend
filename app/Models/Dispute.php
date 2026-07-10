<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisputeCategory;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Observers\DisputeObserver;
use App\Traits\FilterQueries\DisputeFilterQuery;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Delivery\Models\DeliveryDriverTrustLog;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[ObservedBy([DisputeObserver::class])]
final class Dispute extends Model implements HasMedia
{
    use DisputeFilterQuery;
    use InteractsWithMedia;

    protected $fillable = [
        'booking_id',
        'booking_type',
        'ticket_number',
        'description',
        'category',
        'status',
        'resolution',
        'worker_earnings_frozen',
    ];

    public function booking(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'booking_type', 'booking_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class);
    }

    public function trustLogs(): HasMany
    {
        return $this->hasMany(DeliveryDriverTrustLog::class, 'related_dispute_id');
    }

    public function casts(): array
    {
        return [
            'category' => DisputeCategory::class,
            'status' => DisputeStatus::class,
            'resolution' => DisputeResolution::class,
            'worker_earnings_frozen' => 'boolean',
        ];
    }
}
