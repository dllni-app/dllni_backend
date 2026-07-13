<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\DisputeMessageObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ObservedBy([DisputeMessageObserver::class])]
final class DisputeMessage extends Model
{
    protected $fillable = [
        'dispute_id',
        'sender_id',
        'sender_type',
        'body',
    ];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
