<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

final class CleaningSpecialServiceCategory extends Model
{
    protected $fillable = ['name', 'slug', 'sort_order', 'is_active'];

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            if (blank($category->slug)) {
                $slug = Str::slug((string) $category->name);
                $category->slug = $slug !== '' ? $slug : 'category-'.substr(hash('sha256', (string) $category->name), 0, 16);
            }
        });
    }

    public function services(): HasMany
    {
        return $this->hasMany(CleaningSpecialService::class);
    }

    public function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
