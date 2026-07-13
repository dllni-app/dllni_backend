<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupportCaseKind;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseReporterRole;
use App\Enums\SupportCaseResolution;
use App\Enums\SupportCaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class SupportCase extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'case_number',
        'kind',
        'priority',
        'booking_id',
        'booking_type',
        'reporter_id',
        'reporter_role',
        'category',
        'description',
        'status',
        'resolution',
        'resolution_note',
        'latitude',
        'longitude',
        'worker_earnings_frozen',
        'acknowledged_by',
        'acknowledged_at',
        'resolved_by',
        'resolved_at',
        'context',
        'client_request_id',
        'legacy_type',
        'legacy_id',
    ];

    public function booking(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'booking_type', 'booking_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportCaseMessage::class)->oldest();
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportCaseEvent::class)->oldest();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->acceptsFile(static fn (Media $media): bool => str_starts_with((string) $media->mime_type, 'image/'));
    }

    public function casts(): array
    {
        return [
            'kind' => SupportCaseKind::class,
            'priority' => SupportCasePriority::class,
            'reporter_role' => SupportCaseReporterRole::class,
            'status' => SupportCaseStatus::class,
            'resolution' => SupportCaseResolution::class,
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'worker_earnings_frozen' => 'boolean',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'context' => 'array',
        ];
    }
}
