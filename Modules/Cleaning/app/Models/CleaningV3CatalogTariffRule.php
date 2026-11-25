<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Draft-only tariff and workforce-effort rule. Not an approved, signed quote.
 * A published catalog cannot be mutated even by a direct Eloquent save.
 */
final class CleaningV3CatalogTariffRule extends Model
{
    use LocksCleaningV3CatalogDraft;

    public const MODES = ['regular', 'deep'];

    protected $table = 'cleaning_v3_catalog_tariff_rules';

    protected $attributes = ['currency' => 'SYP', 'scope_key' => 'whole'];

    protected $fillable = [
        'catalog_item_id', 'rule_key', 'cleaning_mode', 'scope_key',
        'quantity_min', 'quantity_max', 'unit_price', 'unit_effort_minutes',
        'is_enabled', 'sort_order',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(CleaningV3CatalogItem::class, 'catalog_item_id');
    }

    protected static function booted(): void
    {
        self::saving(static function (self $rule): void {
            $item = $rule->item()->with('version')->first();
            if (! $item instanceof CleaningV3CatalogItem || $item->version === null) {
                throw ValidationException::withMessages([
                    'catalog_item_id' => 'A persisted V3 catalog item is required.',
                ]);
            }
            $item->version->assertEditable();

            // Read raw input before Laravel's decimal:2 casting can round an
            // invalid three-decimal financial input into a valid-looking one.
            $raw = $rule->getAttributes();
            $min = (string) ($raw['quantity_min'] ?? '');
            $max = (string) ($raw['quantity_max'] ?? '');
            $price = (string) ($raw['unit_price'] ?? '');
            $effort = (string) ($raw['unit_effort_minutes'] ?? '');

            if (! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', (string) $rule->rule_key)
                || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', (string) $rule->scope_key)
                || ! in_array((string) $rule->cleaning_mode, self::MODES, true)
                || ! self::quantity($min)
                || ! self::quantity($max)
                || (float) $max <= (float) $min
                || ! self::money($price)
                || ! self::effort($effort)
                || $rule->currency !== 'SYP') {
                throw ValidationException::withMessages([
                    'tariff' => 'V3 tariff requires valid scope, positive SYP price, effort and a nonempty quantity range.',
                ]);
            }
        });

        self::deleting(static fn (self $rule) => $rule->item?->version?->assertEditable());
    }

    protected function casts(): array
    {
        return [
            'quantity_min' => 'decimal:2',
            'quantity_max' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'unit_effort_minutes' => 'decimal:2',
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    private static function quantity(string $amount): bool
    {
        return preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', $amount) === 1;
    }

    private static function money(string $amount): bool
    {
        return preg_match('/^(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,2})?$/D', $amount) === 1
            && (float) $amount > 0;
    }

    private static function effort(string $minutes): bool
    {
        return preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\.[0-9]{1,2})?$/D', $minutes) === 1
            && (float) $minutes > 0
            && (float) $minutes <= 480;
    }
}
