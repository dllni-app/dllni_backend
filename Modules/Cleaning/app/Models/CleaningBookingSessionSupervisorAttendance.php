<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningBookingSessionSupervisorAttendance extends Model
{
    protected $fillable = [
        'cleaning_booking_session_id',
        'cleaning_booking_session_supervisor_assignment_id',
        'started_travel_at',
        'arrived_at',
        'last_location_latitude',
        'last_location_longitude',
        'location_updated_at',
        'last_actor_user_id',
        'version',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function supervisorAssignment(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSessionSupervisorAssignment::class, 'cleaning_booking_session_supervisor_assignment_id');
    }

    public function lastActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_actor_user_id');
    }

    protected function casts(): array
    {
        return [
            'started_travel_at' => 'datetime',
            'arrived_at' => 'datetime',
            'last_location_latitude' => 'float',
            'last_location_longitude' => 'float',
            'location_updated_at' => 'datetime',
            'version' => 'integer',
        ];
    }
}
