<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class AdminNotificationBroadcast extends Model
{
    public const AUDIENCE_ALL = 'all';

    public const AUDIENCE_MODULE_TYPES = 'module_types';

    public const AUDIENCE_SPECIFIC_USERS = 'specific_users';

    protected $fillable = [
        'title',
        'body',
        'audience_type',
        'module_types',
        'recipients_count',
        'created_by_user_id',
        'sent_at',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'admin_notification_broadcast_user');
    }

    protected function casts(): array
    {
        return [
            'module_types' => 'array',
            'recipients_count' => 'integer',
            'sent_at' => 'datetime',
        ];
    }
}
