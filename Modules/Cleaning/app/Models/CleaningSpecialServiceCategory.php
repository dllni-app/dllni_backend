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
            if (filled($category->slug)) {
                return;
            }

            $name = mb_trim((string) $category->name);
            $base = Str::slug($name);
            if ($base === '') {
                // Arabic-only names should still generate a readable slug.
                $base = trim((string) preg_replace('/[^\\p{L}\\p{N}]+/u', '-', mb_strtolower($name)), '-');
            }
            $base = mb_substr($base !== '' ? $base : 'category', 0, 160);

            $slug = $base;
            $suffix = 2;
            while (self::query()->where('slug', $slug)->exists()) {
                $addition = '-'.$suffix++;
                $slug = mb_substr($base, 0, 160 - strlen($addition)).$addition;
            }

            $category->slug = $slug;
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
