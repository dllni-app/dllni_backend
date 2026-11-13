<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class CuisineType extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    /** Stable public artwork, independent of any restaurant's cover photo. */
    public function iconUrl(): string
    {
        $slug = in_array($this->slug, ['italian', 'asian', 'american', 'mediterranean'], true)
            ? $this->slug : 'default';

        return asset('images/cuisines/'.$slug.'.svg');
    }

    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'cuisine_type_restaurant')
            ->withTimestamps();
    }
}
