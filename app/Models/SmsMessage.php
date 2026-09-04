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
        'queued_at',
        'job_started_at',
        'queue_wait_ms',
        'provider_execution_ms',
        'job_execution_ms',
        'job_finished_at',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'lang' => 'integer',
            'provider_status_code' => 'integer',
            'attempts_count' => 'integer',
            'queued_at' => 'datetime',
            'job_started_at' => 'datetime',
            'queue_wait_ms' => 'integer',
            'provider_execution_ms' => 'integer',
            'job_execution_ms' => 'integer',
            'job_finished_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function smsable(): MorphTo
    {
        return $this->morphTo();
    }
}
