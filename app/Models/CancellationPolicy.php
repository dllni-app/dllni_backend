<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CancellationPolicy extends Model
{
    protected $fillable = [
        'module',
        'name',
        'description',
        'rules',
        'is_active',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
