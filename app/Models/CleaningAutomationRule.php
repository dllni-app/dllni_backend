<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CleaningAutomationRule extends Model
{
    protected $fillable = [
        'name',
        'type',
        'is_active',
        'conditions',
        'actions',
    ];

    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'conditions' => 'array',
            'actions' => 'array',
        ];
    }
}
