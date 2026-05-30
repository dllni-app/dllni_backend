<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeliveryFinancialTransaction extends Model
{
    protected $table = 'delivery_financial_transactions';

    protected $fillable = ['account_id', 'transaction_type', 'direction', 'amount', 'balance_before', 'balance_after', 'reference_type', 'reference_id', 'note', 'metadata', 'created_by_user_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(DeliveryFinancialAccount::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_user_id')->withDefault();
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_before' => 'decimal:2', 'balance_after' => 'decimal:2', 'metadata' => 'json'];
    }
}
