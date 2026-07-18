<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CleaningHomeType extends Model
{
    use SoftDeletes;

    public const SECTION_PROPERTY = 'property';

    public const SECTION_OCCASION = 'occasion';

    protected $fillable = [
        'section',
        'code',
        'title',
        'image_path',
        'external_image_url',
        'sort_order',
        'is_active',
    ];

    public function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function imageUrl(): ?string
    {
        if ($this->image_path !== null && $this->image_path !== '') {
            $url = Storage::disk('public')->url($this->image_path);

            return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
        }

        $externalUrl = trim((string) $this->external_image_url);

        return $externalUrl !== '' ? $externalUrl : null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForSection(Builder $query, string $section): Builder
    {
        return $query->where('section', $section);
    }
}
