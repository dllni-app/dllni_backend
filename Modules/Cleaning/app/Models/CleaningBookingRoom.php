<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Booked room descriptions are shared by the whole team, not owned by workers. */
final class CleaningBookingRoom extends Model
{
    use HasFactory;

    protected $fillable = ['cleaning_booking_id', 'room_key', 'room_type', 'room_size', 'display_label', 'weight'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function casts(): array
    {
        return ['weight' => 'decimal:2'];
    }
}
