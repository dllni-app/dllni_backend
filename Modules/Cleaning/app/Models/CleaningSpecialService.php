<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CleaningSpecialService extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'image_url',
        'image_path',
        'pricing_unit',
        'base_unit_price',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (CleaningSpecialService $service): void {
            if (blank($service->slug)) {
                $service->slug = Str::slug((string) $service->name) ?: 'special-service-'.Str::random(8);
            }
        });
    }

    public function dirtinessRules(): HasMany
    {
        return $this->hasMany(CleaningSpecialServiceDirtinessRule::class);
    }

    public function equipment(): BelongsToMany
    {
        return $this->belongsToMany(
            CleaningSpecialServiceEquipment::class,
            'cleaning_special_service_equipment_map',
        )->withTimestamps();
    }

    public function bookingLines(): HasMany
    {
        return $this->hasMany(CleaningBookingSpecialService::class);
    }

    public function imageUrl(): ?string
    {
        $imagePath = trim((string) $this->image_path);
        if ($imagePath !== '') {
            $url = Storage::disk('public')->url($imagePath);

            return Str::startsWith($url, ['http://', 'https://']) ? $url : url($url);
        }

        $externalUrl = trim((string) $this->image_url);

        return $externalUrl !== '' ? $externalUrl : null;
    }

    public function casts(): array
    {
        return ['base_unit_price' => 'float', 'is_active' => 'boolean'];
    }
}
