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
        'booking_value',
        'title',
        'image_path',
        'external_image_url',
        'sort_order',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $type): void {
            $section = trim((string) $type->section);

            if (blank($type->code)) {
                $type->code = self::generateUniqueCode($section, (string) $type->title);
            }

            if (blank($type->booking_value)) {
                $type->booking_value = $type->code;
            }

            if ($type->sort_order === null) {
                $type->sort_order = ((int) static::query()
                    ->withTrashed()
                    ->where('section', $section)
                    ->max('sort_order')) + 1;
            }
        });
    }

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

    private static function generateUniqueCode(string $section, string $title): string
    {
        $baseCode = Str::slug($title, '_');

        if ($baseCode === '') {
            $prefix = $section !== '' ? $section : 'type';
            $baseCode = $prefix.'_'.substr(sha1($title), 0, 10);
        }

        $baseCode = Str::limit($baseCode, 90, '');
        $code = $baseCode;
        $suffix = 2;

        while (static::query()
            ->withTrashed()
            ->where('section', $section)
            ->where('code', $code)
            ->exists()) {
            $suffixValue = '_'.$suffix;
            $code = Str::limit($baseCode, 100 - strlen($suffixValue), '').$suffixValue;
            $suffix++;
        }

        return $code;
    }
}
