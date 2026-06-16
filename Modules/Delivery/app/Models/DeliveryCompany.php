<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Database\Factories\DeliveryCompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

final class DeliveryCompany extends Model
{
    use HasFactory;

    protected $table = 'delivery_companies';

    protected $fillable = [
        'owner_user_id', 'name', 'legal_name', 'phone', 'email', 'address',
        'latitude', 'longitude', 'is_active', 'is_suspended', 'suspension_reason',
        'suspended_until', 'financial_limit',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'owner_user_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(DeliveryCompanyStaff::class, 'company_id');
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(DeliveryDriver::class, 'company_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(DeliveryOrder::class, 'company_id');
    }

    public function financialAccount(): MorphOne
    {
        return $this->morphOne(DeliveryFinancialAccount::class, 'owner');
    }

    protected static function newFactory(): DeliveryCompanyFactory
    {
        return DeliveryCompanyFactory::new();
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'is_active' => 'boolean',
            'is_suspended' => 'boolean',
            'suspended_until' => 'datetime',
            'financial_limit' => 'decimal:2',
        ];
    }
}
