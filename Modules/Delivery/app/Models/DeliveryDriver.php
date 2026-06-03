<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Database\Factories\DeliveryDriverFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

final class DeliveryDriver extends Model
{
    use HasFactory;

    protected $table = 'delivery_drivers';

    protected $fillable = ['company_id', 'user_id', 'first_name', 'phone', 'vehicle_type', 'plate_number', 'availability_status', 'is_active', 'is_suspended', 'suspended_until', 'suspension_reason', 'trust_score', 'open_disputes_count', 'last_seen_at'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(DeliveryCompany::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(DeliveryDriverLocation::class, 'driver_id');
    }

    public function latestLocation(): HasOne
    {
        return $this->hasOne(DeliveryDriverLocation::class, 'driver_id')->latestOfMany('recorded_at');
    }

    public function assignmentAttempts(): HasMany
    {
        return $this->hasMany(DeliveryAssignmentAttempt::class, 'driver_id');
    }

    public function trustLogs(): HasMany
    {
        return $this->hasMany(DeliveryDriverTrustLog::class, 'driver_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class, 'driver_id');
    }

    public function financialAccount(): MorphOne
    {
        return $this->morphOne(DeliveryFinancialAccount::class, 'owner');
    }

    protected static function newFactory(): DeliveryDriverFactory
    {
        return DeliveryDriverFactory::new();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_suspended' => 'boolean', 'suspended_until' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
