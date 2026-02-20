<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SmOrderStatusLog extends Model
{
    protected $table = 'sm_order_status_logs';

    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'notes',
        'changed_by_user_id',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SmOrder::class, 'order_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
