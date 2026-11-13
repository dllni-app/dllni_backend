<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Image;
use Throwable;

/** For image uploads outside Spatie Media Library (e.g. cleaning catalog). */
final class DirectImageVariants
{
    public static function store(UploadedFile $file, string $disk, string $directory): string
    {
        $extension = $file->guessExtension() ?: 'jpg';
        $original = trim($directory, '/').'/'.Str::uuid().'.'.$extension;
        $stored = $file->storeAs(dirname($original), basename($original), $disk);
        if ($stored === false) {
            throw new RuntimeException('Could not store uploaded image.');
        }
        try {
            self::generate($file, $disk, $original);
        } catch (Throwable $error) {
            Storage::disk($disk)->delete($original);
            throw $error;
        }

        return self::path($original, 'webp');
    }

    public static function path(string $original, string $variant): string
    {
        return $original.($variant === 'thumb' ? '.thumb.webp' : '.webp');
    }

    /** Generate sidecars on the original disk without altering source bytes. */
    public static function generate(UploadedFile $file, string $disk, string $original): void
    {
        $filesystem = Storage::disk($disk);
        $tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'image-'.Str::uuid();
        if (! mkdir($tempDir, 0700)) {
            throw new RuntimeException('Could not create image conversion directory.');
        }
        $written = [];
        try {
            foreach (['thumb' => [640, 78], 'webp' => [1600, 84]] as $variant => [$size, $quality]) {
                $temp = $tempDir.DIRECTORY_SEPARATOR.$variant.'.webp';
                Image::load($file->getRealPath())->fit(Fit::Max, $size, $size)
                    ->format('webp')->quality($quality)->save($temp);
                $path = self::path($original, $variant);
                $stream = fopen($temp, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('Could not read converted image.');
                }
                try {
                    if (! $filesystem->put($path, $stream)) {
                        throw new RuntimeException('Could not write converted image.');
                    }
                    $written[] = $path;
                } finally {
                    fclose($stream);
                }
            }
        } catch (Throwable $error) {
            $filesystem->delete($written);
            throw $error;
        } finally {
            foreach (glob($tempDir.DIRECTORY_SEPARATOR.'*') ?: [] as $temp) {
                unlink($temp);
            }
            rmdir($tempDir);
        }
    }
}
