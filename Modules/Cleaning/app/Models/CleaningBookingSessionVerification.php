<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningBookingSessionVerification extends Model
{
    protected $fillable = [
        'cleaning_booking_session_id',
        'cleaning_booking_session_supervisor_assignment_id',
        'customer_user_id',
        'code_hash',
        'customer_code_ciphertext',
        'status',
        'attempts',
        'max_attempts',
        'expires_at',
        'verified_at',
        'consumed_at',
        'invalidated_at',
        'active_slot',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function supervisorAssignment(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSessionSupervisorAssignment::class, 'cleaning_booking_session_supervisor_assignment_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'consumed_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'active_slot' => 'integer',
        ];
    }
}
