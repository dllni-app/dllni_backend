<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class SmsMessage extends Model
{
    protected $fillable = [
        'smsable_type',
        'smsable_id',
        'provider',
        'gsm',
        'message',
        'lang',
        'status',
        'provider_status_code',
        'provider_response',
        'attempts_count',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'lang' => 'integer',
            'provider_status_code' => 'integer',
            'attempts_count' => 'integer',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function smsable(): MorphTo
    {
        return $this->morphTo();
    }
}
