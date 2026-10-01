<?php

declare(strict_types=1);

use App\Notifications\Core\NotificationTypeRegistry;

it('keeps Arabic notification templates available for all configured notification types', function (): void {
    expect(app(NotificationTypeRegistry::class)->defaultLocale())->toBe('ar');

    $configFiles = [
        'notification_types.php',
        'notification_type_extensions.php',
        'cleaning_recurring_notification_types.php',
        'cleaning_repeated_notification_types.php',
        'platform_coupon_notification_types.php',
    ];

    $checked = 0;

    foreach ($configFiles as $fileName) {
        $path = config_path($fileName);

        if (! is_file($path)) {
            continue;
        }

        $config = require $path;
        $types = is_array($config['types'] ?? null) ? $config['types'] : [];

        foreach ($types as $canonicalType => $definition) {
            $templates = is_array($definition['templates'] ?? null) ? $definition['templates'] : [];

            if ($templates === []) {
                continue;
            }

            $arabic = $templates['ar'] ?? null;

            expect($arabic)
                ->toBeArray("Missing Arabic template for [{$canonicalType}] in {$fileName}");

            $title = mb_trim((string) ($arabic['title'] ?? ''));
            $body = mb_trim((string) ($arabic['body'] ?? ''));

            expect($title)->not->toBe('')
                ->and(preg_match('/\p{Arabic}/u', $title))->toBe(
                    1,
                    "Arabic notification title for [{$canonicalType}] does not contain Arabic copy",
                )
                ->and($body)->not->toBe('');

            $bodyIsArabic = preg_match('/\p{Arabic}/u', $body) === 1;
            $bodyIsDynamicPlaceholder = preg_match('/^:[A-Za-z0-9_]+$/', $body) === 1;

            expect($bodyIsArabic || $bodyIsDynamicPlaceholder)->toBeTrue(
                "Arabic notification body for [{$canonicalType}] is neither Arabic copy nor a dynamic placeholder",
            );

            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(0);
});
