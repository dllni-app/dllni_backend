<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DeepLinkEvent extends Model
{
    protected $fillable = [
        'action',
        'status',
        'resource_type',
        'resource_id',
        'resource_slug',
        'source',
        'medium',
        'campaign',
        'sharer_id',
        'platform',
        'user_agent',
        'ip',
        'referer',
        'full_url',
        'path',
        'query_params',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'query_params' => 'array',
            'meta' => 'array',
        ];
    }
}
