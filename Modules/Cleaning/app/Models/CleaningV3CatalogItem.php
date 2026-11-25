<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Services\CleaningV3CatalogDraftValidator;

final class CleaningV3CatalogItem extends Model
{
    use LocksCleaningV3CatalogDraft;

    public const SOURCE_TYPES = ['property', 'room', 'service', 'special_service', 'material', 'event_type', 'equipment'];

    public const MEASUREMENT_MODES = ['count', 'sqm', 'linear_meter', 'piece', 'hour', 'fixed'];

    protected $table = 'cleaning_v3_catalog_items';

    protected $fillable = [
        'catalog_version_id', 'item_key', 'source_type', 'source_id', 'label_ar',
        'label_en', 'measurement_mode', 'input_rules', 'required', 'is_enabled', 'sort_order',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(CleaningV3CatalogVersion::class, 'catalog_version_id');
    }

    public function presets(): HasMany
    {
        return $this->hasMany(CleaningV3CatalogSizePreset::class, 'catalog_item_id');
    }

    public function tariffRules(): HasMany
    {
        return $this->hasMany(CleaningV3CatalogTariffRule::class, 'catalog_item_id');
    }

    protected static function booted(): void
    {
        self::saving(static function (self $item): void {
            $version = $item->version()->first();
            if (! $version) {
                throw ValidationException::withMessages(['catalog_version_id' => 'A persisted catalog draft is required.']);
            }
            $version->assertEditable();
            if (! in_array($item->source_type, self::SOURCE_TYPES, true)
                || ! in_array($item->measurement_mode, self::MEASUREMENT_MODES, true)
                || (int) $item->source_id < 1
                || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', (string) $item->item_key)) {
                throw ValidationException::withMessages(['item_key' => 'Invalid V3 catalog item contract.']);
            }
            app(CleaningV3CatalogDraftValidator::class)->assertActiveSource(
                (string) $item->source_type, (int) $item->source_id,
            );
        });
        self::deleting(static fn (self $item) => $item->version?->assertEditable());
    }

    protected function casts(): array
    {
        return [
            'input_rules' => 'array',
            'required' => 'boolean',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
