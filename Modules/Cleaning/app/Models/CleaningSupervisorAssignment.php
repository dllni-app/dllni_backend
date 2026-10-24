<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningSupervisorAssignment extends Model
{
    protected $fillable = ['cleaning_booking_id', 'supervisor_user_id', 'assigned_by_user_id', 'assigned_at', 'released_at', 'reason'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_user_id');
    }
}
