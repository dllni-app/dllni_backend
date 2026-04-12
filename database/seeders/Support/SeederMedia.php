<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Throwable;

final class SeederMedia
{
    private const string PlaceholderPngBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAtMB9dZYkEYAAAAASUVORK5CYII=';

    /**
     * @param  Model&HasMedia  $model
     */
    public static function ensureSingleMedia(Model $model, string $collection, string $remoteUrl, string $seed): void
    {
        if ($model->getFirstMedia($collection) !== null) {
            return;
        }

        if (! app()->runningUnitTests()) {
            try {
                $model->addMediaFromUrl($remoteUrl)->toMediaCollection($collection);

                return;
            } catch (Throwable) {
                // Continue with local fallback.
            }
        }

        $tempPath = self::createLocalPlaceholder($seed);
        if ($tempPath === null) {
            return;
        }

        try {
            $model->addMedia($tempPath)
                ->usingFileName(self::safeFileName($seed).'.png')
                ->toMediaCollection($collection);
        } catch (Throwable) {
            // Ignore media failures in dev seed data.
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private static function safeFileName(string $seed): string
    {
        $slug = Str::slug($seed, '-');

        return $slug !== '' ? $slug : 'seed-media';
    }

    private static function createLocalPlaceholder(string $seed): ?string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'seed-media-');
        if ($tempPath === false) {
            return null;
        }

        $pngPath = $tempPath.'-'.self::safeFileName($seed).'.png';
        @unlink($tempPath);

        $decoded = base64_decode(self::PlaceholderPngBase64, true);
        if (! is_string($decoded) || $decoded === '') {
            return null;
        }

        $bytes = file_put_contents($pngPath, $decoded);
        if ($bytes === false || $bytes === 0) {
            @unlink($pngPath);

            return null;
        }

        return $pngPath;
    }
}
