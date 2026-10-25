<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Modules\Cleaning\Support\CleaningFinancialDefaults;

final class CleaningRoomType extends Model
{
    protected $fillable = ['code', 'name', 'icon_key', 'regular_description', 'deep_description', 'regular_hourly_rate', 'deep_hourly_rate', 'suggested_minutes_by_size', 'sort_order', 'is_active'];

    public function casts(): array
    {
        return [
            'regular_hourly_rate' => 'decimal:2',
            'deep_hourly_rate' => 'decimal:2',
            'suggested_minutes_by_size' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return array<string, self> */
    public static function catalog(): array
    {
        if (! Schema::hasTable('cleaning_room_types')) {
            return [];
        }

        return self::query()->get()->keyBy('code')->all();
    }

    /** @return list<string> */
    public static function availableCodes(): array
    {
        if (! Schema::hasTable('cleaning_room_types')) {
            return CleaningFinancialDefaults::ROOM_TYPES;
        }

        return self::query()->where('is_active', true)->orderBy('sort_order')->pluck('code')->all();
    }
}
