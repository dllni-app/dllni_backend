<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\CleaningDepositTransaction;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningBookingSessionFinancialPenalty extends Model
{
    public const ROLE_WORKER = 'worker';

    public const ROLE_CUSTOMER = 'customer';

    public const SOURCE_DEBT = 'debt';

    public const SOURCE_CUSTOMER_FEE = 'customer_fee';

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'cleaning_booking_id',
        'cleaning_booking_session_id',
        'worker_id',
        'customer_id',
        'financial_transaction_id',
        'reference_key',
        'penalized_role',
        'financial_source',
        'amount',
        'status',
        'reason_snapshot',
        'applied_at',
    ];

    public function casts(): array
    {
        return [
            'amount' => 'float',
            'applied_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(CleaningDepositTransaction::class, 'financial_transaction_id');
    }
}
