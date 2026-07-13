<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SupportCaseReporterRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class SupportCaseMessage extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'support_case_id',
        'sender_id',
        'sender_role',
        'body',
    ];

    public function supportCase(): BelongsTo
    {
        return $this->belongsTo(SupportCase::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->acceptsFile(static fn (Media $media): bool => str_starts_with((string) $media->mime_type, 'image/'));
    }

    public function casts(): array
    {
        return [
            'sender_role' => SupportCaseReporterRole::class,
        ];
    }
}
