<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WorkerComplianceReviewCase extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_EXPLAINED = 'explained';

    public const STATUS_INCONCLUSIVE = 'inconclusive';

    public const STATUS_CONFIRMED_VIOLATION = 'confirmed_violation';

    public const RESOLVED_STATUSES = [
        self::STATUS_EXPLAINED,
        self::STATUS_INCONCLUSIVE,
        self::STATUS_CONFIRMED_VIOLATION,
    ];

    protected $table = 'worker_compliance_review_cases';

    protected $guarded = ['id'];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WorkerComplianceReviewEvent::class, 'case_id')->orderBy('created_at');
    }

    public function casts(): array
    {
        return [
            'observation_started_at' => 'datetime',
            'observation_ended_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
