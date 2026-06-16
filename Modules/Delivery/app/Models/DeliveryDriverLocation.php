<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeliveryDriverLocation extends Model
{
    protected $table = 'delivery_driver_locations';

    protected $fillable = ['driver_id', 'latitude', 'longitude', 'accuracy', 'speed', 'heading', 'recorded_at'];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(DeliveryDriver::class);
    }

    protected function casts(): array
    {
        return ['latitude' => 'decimal:8', 'longitude' => 'decimal:8', 'accuracy' => 'decimal:2', 'speed' => 'decimal:2', 'heading' => 'decimal:2', 'recorded_at' => 'datetime'];
    }
}
