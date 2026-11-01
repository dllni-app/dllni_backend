<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class CleaningTimePeriod extends Model
{
    protected $guarded = ['id'];

    public function schedules(): BelongsToMany
    {
        return $this->belongsToMany(CleaningRoomSchedule::class, 'cleaning_time_period_room_schedules');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'lineage' => 'array'];
    }
}
