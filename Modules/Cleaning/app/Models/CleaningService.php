<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Cleaning\Enums\ServiceCategory;
use Modules\Cleaning\Traits\FilterQueries\CleaningServiceFilterQuery;

final class CleaningService extends Model
{
    use CleaningServiceFilterQuery;

    protected $fillable = [
        'name',
        'slug',
        'category',
        'description',
        'price',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (CleaningService $service): void {
            if (blank($service->slug)) {
                $service->slug = self::uniqueSlug((string) $service->name);
            }
        });

        static::updating(function (CleaningService $service): void {
            if ($service->isDirty('name') && ! $service->isDirty('slug')) {
                $service->slug = self::uniqueSlug((string) $service->name, $service->getKey());
            }
        });
    }

    public function pricing(): HasMany
    {
        return $this->hasMany(ServicePricing::class);
    }

    public function casts(): array
    {
        return [
            'category' => ServiceCategory::class,
            'price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    private static function uniqueSlug(string $name, int|string|null $ignoreId = null): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'service';
        }

        $slug = $base;
        $counter = 2;

        while (self::query()
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
