<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

final class CleaningSpecialServiceEquipment extends Model
{
    protected $fillable = [
        'name', 'asset_code', 'status', 'buffer_before_minutes', 'buffer_after_minutes',
        'last_handed_over_at', 'last_returned_at', 'is_active',
    ];

    public function specialServices(): BelongsToMany
    {
        return $this->belongsToMany(
            CleaningSpecialService::class,
            'cleaning_special_service_equipment_map',
        )->withTimestamps();
    }

    public function casts(): array
    {
        return [
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'last_handed_over_at' => 'datetime',
            'last_returned_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (self $equipment): void {
            if (! $equipment->isDirty('status') || $equipment->status !== 'available') {
                return;
            }
            if ($equipment->getOriginal('status') === 'lost') {
                throw ValidationException::withMessages(['status' => ['Recover lost equipment through the audited recovery action.']]);
            }
            // A direct admin form save must not bypass the return approval workflow.
            $unconfirmed = CleaningEquipmentReservation::query()
                ->where('cleaning_special_service_equipment_id', $equipment->id)
                ->whereIn('status', [
                    'handed_over', 'acknowledged',
                    'return_pending_confirmation', 'failure_pending_confirmation',
                ])->exists();
            if ($unconfirmed) {
                throw ValidationException::withMessages(['status' => ['Equipment return must be confirmed administratively before the asset becomes available.']]);
            }
        });
    }
}
