<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CleaningEventType extends Model
{
    protected $fillable = ['name', 'slug', 'sort_order', 'is_active'];

    public function fields(): HasMany
    {
        return $this->hasMany(CleaningEventTypeField::class)->orderBy('sort_order');
    }

    public function specialServices(): BelongsToMany
    {
        return $this->belongsToMany(CleaningSpecialService::class, 'cleaning_event_type_special_services')->withTimestamps();
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(CleaningBooking::class);
    }

    public function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
