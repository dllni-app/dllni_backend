<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Supermarket\Enums\SmDisputeStatus;
use Modules\Supermarket\Traits\FilterQueries\SmOrderDisputeFilterQuery;

final class SmOrderDispute extends Model
{
    use SmOrderDisputeFilterQuery;

    protected $table = 'sm_order_disputes';

    protected $fillable = [
        'order_id',
        'opened_by_user_id',
        'ticket_number',
        'status',
        'reason',
        'description',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_notes',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(SmOrder::class, 'order_id');
    }

    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SmOrderDisputeMessage::class, 'dispute_id');
    }

    protected function casts(): array
    {
        return [
            'status' => SmDisputeStatus::class,
            'resolved_at' => 'datetime',
        ];
    }
}
