<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DeepLinkShortUrl extends Model
{
    protected $fillable = [
        'code',
        'target_url',
        'is_active',
        'clicks',
        'max_clicks',
        'expires_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }
}
