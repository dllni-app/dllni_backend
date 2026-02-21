<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Supermarket\Traits\FilterQueries\SmOrderDisputeMessageFilterQuery;

final class SmOrderDisputeMessage extends Model
{
    use SmOrderDisputeMessageFilterQuery;

    protected $table = 'sm_order_dispute_messages';

    protected $fillable = [
        'dispute_id',
        'user_id',
        'message',
        'is_internal',
    ];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(SmOrderDispute::class, 'dispute_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
        ];
    }
}
