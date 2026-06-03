<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Database\Factories\CleaningBannerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CleaningBanner extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'subtitle',
        'image_path',
        'target_url',
        'sort_order',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    public function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function imageUrl(): ?string
    {
        if ($this->image_path === null || $this->image_path === '') {
            return null;
        }

        $url = Storage::disk('public')->url($this->image_path);

        return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
    }

    public function scopeVisibleNow(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            });
    }

    protected static function newFactory(): CleaningBannerFactory
    {
        return CleaningBannerFactory::new();
    }
}
