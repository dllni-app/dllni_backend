<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeliveryDriverTrustLog extends Model
{
    protected $table = 'delivery_driver_trust_logs';

    protected $fillable = ['driver_id', 'reason', 'score_delta', 'score_after', 'related_dispute_id'];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DeliveryDriver::class);
    }

    public function relatedDispute(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Dispute::class, 'related_dispute_id')->withDefault();
    }
}
