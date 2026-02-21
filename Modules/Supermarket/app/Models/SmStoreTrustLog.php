<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Supermarket\Traits\FilterQueries\SmStoreTrustLogFilterQuery;

final class SmStoreTrustLog extends Model
{
    use SmStoreTrustLogFilterQuery;

    protected $table = 'sm_store_trust_logs';

    protected $fillable = [
        'store_id',
        'event_type',
        'score_delta',
        'score_after',
        'reference_type',
        'reference_id',
        'notes',
        'triggered_by_user_id',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }
}
