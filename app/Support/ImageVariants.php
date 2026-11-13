<?php

declare(strict_types=1);

namespace App\Support;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class ImageVariants
{
    public const THUMB = 'app-thumb';
    public const WEBP = 'app-webp';

    public static function register(HasMedia $model, ?Media $media): void
    {
        // Preserve originals and do not convert PDFs, videos, SVGs or animated GIFs.
        if ($media !== null && ! in_array($media->mime_type, ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)) {
            return;
        }
        $model->addMediaConversion(self::THUMB)
            ->fit(Fit::Max, 640, 640)->format('webp')->quality(78)->nonQueued();
        $model->addMediaConversion(self::WEBP)
            ->fit(Fit::Max, 1600, 1600)->format('webp')->quality(84)->nonQueued();
    }

    public static function url(HasMedia $model, string $collection, bool $thumbnail = false): ?string
    {
        $media = $model->getFirstMedia($collection);
        return $media ? self::mediaUrl($media, $thumbnail) : null;
    }

    public static function mediaUrl(Media $media, bool $thumbnail = false): string
    {
        return $media->getAvailableUrl($thumbnail ? [self::THUMB, self::WEBP] : [self::WEBP]);
    }
}