<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

final class CleaningV3CatalogSizePreset extends Model
{
    protected $table = 'cleaning_v3_catalog_size_presets';

    protected $fillable = [
        'catalog_item_id', 'preset_key', 'label_ar', 'label_en',
        'minimum_value', 'maximum_value', 'sort_order',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(CleaningV3CatalogItem::class, 'catalog_item_id');
    }

    protected static function booted(): void
    {
        self::saving(static function (self $preset): void {
            $item = $preset->item()->with('version')->first();
            if (! $item) {
                throw ValidationException::withMessages(['catalog_item_id' => 'A catalog item is required.']);
            }
            $item->version->assertEditable();
            if (! in_array($item->measurement_mode, ['sqm', 'linear_meter', 'count', 'piece', 'hour'], true)
                || (float) $preset->minimum_value < 0
                || (float) $preset->maximum_value <= (float) $preset->minimum_value
                || ! preg_match('/^[a-z][a-z0-9_.-]{0,59}$/D', (string) $preset->preset_key)) {
                throw ValidationException::withMessages(['preset_key' => 'Invalid V3 item size preset.']);
            }
        });
        self::deleting(static fn (self $preset) => $preset->item?->version?->assertEditable());
    }

    protected function casts(): array
    {
        return [
            'minimum_value' => 'decimal:2',
            'maximum_value' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}
