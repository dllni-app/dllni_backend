<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Lang;

it('keeps all static Filament translation keys available in Arabic and English', function (): void {
    $keys = [];
    $directories = [
        app_path('Filament'),
        resource_path('views/filament'),
    ];

    foreach ($directories as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'blade.php'], true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (! is_string($contents)) {
                continue;
            }

            preg_match_all("/__\(\s*['\"]([^'\"]+)['\"]\s*\)/", $contents, $matches);

            foreach ($matches[1] ?? [] as $key) {
                if (str_contains($key, '{$')) {
                    continue;
                }

                $keys[$key] = true;
            }
        }
    }

    expect($keys)->not->toBeEmpty();

    foreach (['ar', 'en'] as $locale) {
        foreach (array_keys($keys) as $key) {
            expect(Lang::has($key, $locale, false))
                ->toBeTrue("Missing {$locale} translation for [{$key}]");
        }
    }
});
