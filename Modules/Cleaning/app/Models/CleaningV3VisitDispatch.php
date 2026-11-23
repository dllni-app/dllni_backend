<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningV3VisitDispatch extends Model
{
    protected $table = 'cleaning_v3_visit_dispatches';

    protected $guarded = ['*'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    protected function casts(): array
    {
        return [
            'first_search_started_at' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime',
            'escalated_at' => 'immutable_datetime',
            'first_fully_covered_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
