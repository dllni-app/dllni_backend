<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class DeliveryFinancialAccount extends Model
{
    protected $table = 'delivery_financial_accounts';

    protected $fillable = ['owner_type', 'owner_id', 'currency', 'current_balance', 'financial_limit', 'is_suspended', 'suspension_reason', 'suspended_at'];

    public function owner(): MorphTo
    {
        return $this->morphTo('owner');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DeliveryFinancialTransaction::class, 'account_id');
    }

    protected function casts(): array
    {
        return ['current_balance' => 'decimal:2', 'financial_limit' => 'decimal:2', 'is_suspended' => 'boolean', 'suspended_at' => 'datetime'];
    }
}
