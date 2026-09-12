<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CleaningScheduleChangeRequest extends Model
{
    protected $fillable = [
        'cleaning_booking_id', 'customer_id', 'change_type', 'status', 'revision_token',
        'affected_session_ids', 'before_snapshot', 'proposed_snapshot', 'price_delta',
        'customer_resolution', 'resolved_at',
    ];

    public function booking(): BelongsTo { return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(User::class); }
    public function decisions(): HasMany { return $this->hasMany(CleaningScheduleChangeDecision::class); }

    public function casts(): array
    {
        return [
            'affected_session_ids' => 'array', 'before_snapshot' => 'array', 'proposed_snapshot' => 'array',
            'price_delta' => 'float', 'resolved_at' => 'datetime',
        ];
    }
}
