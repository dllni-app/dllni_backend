<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeliveryAssignmentAttempt extends Model
{
    protected $table = 'delivery_assignment_attempts';

    protected $fillable = ['order_id', 'driver_id', 'attempt_no', 'dispatch_wave', 'candidate_tier', 'status', 'distance_to_pickup_km', 'offered_at', 'expires_at', 'responded_at', 'reject_reason'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DeliveryDriver::class);
    }

    protected function casts(): array
    {
        return ['distance_to_pickup_km' => 'decimal:2', 'offered_at' => 'datetime', 'expires_at' => 'datetime', 'responded_at' => 'datetime'];
    }
}
