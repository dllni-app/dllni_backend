<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CleaningSpecialService extends Model
{
    protected $fillable = [
        'name',
        'cleaning_special_service_category_id',
        'description',
        'slug',
        'image_url',
        'image_path',
        'pricing_unit',
        'input_type',
        'unit_code',
        'base_unit_price',
        'supports_dirtiness',
        'gender_constraint',
        'estimated_duration_minutes',
        'worker_pay_mode',
        'worker_pay_value',
        'operating_cost_mode',
        'operating_cost_value',
        'travel_fee_mode',
        'travel_fee_value',
        'requires_before_image',
        'requires_after_image',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(CleaningSpecialServiceCategory::class, 'cleaning_special_service_category_id');
    }

    public function dirtinessLevels(): BelongsToMany
    {
        return $this->belongsToMany(
            CleaningDirtinessLevel::class,
            'cleaning_special_service_dirtiness_levels',
        )->withTimestamps();
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
        return [
            'base_unit_price' => 'float',
            'supports_dirtiness' => 'boolean',
            'estimated_duration_minutes' => 'integer',
            'worker_pay_value' => 'float',
            'operating_cost_value' => 'float',
            'travel_fee_value' => 'float',
            'requires_before_image' => 'boolean',
            'requires_after_image' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
