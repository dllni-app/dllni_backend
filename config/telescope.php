<?php

declare(strict_types=1);

use Laravel\Telescope\Http\Middleware\Authorize;
use Laravel\Telescope\Watchers;

return [
    // Explicit opt-in on this development machine; never auto-discover.
    'enabled' => env('APP_ENV') === 'local' && env('TELESCOPE_ENABLED', false),
    'domain' => null,
    'path' => 'telescope',
    'driver' => 'database',
    'storage' => [
        'database' => ['connection' => 'telescope_local', 'chunk' => 1000],
    ],
    'queue' => ['connection' => 'sync', 'queue' => null, 'delay' => 0],
    'middleware' => ['web', Authorize::class],
    'only_paths' => ['api/*'],
    'ignore_paths' => ['api/login', 'api/*/login', 'api/*/auth/*', 'api/*/password*', 'api/*/otp*'],
    'ignore_commands' => ['telescope:prune', 'telescope:clear', 'telescope:install'],
    'watchers' => [
        Watchers\RequestWatcher::class => [
            'enabled' => true,
            'size_limit' => 32,
            'ignore_http_methods' => ['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'ignore_status_codes' => [],
        ],
        Watchers\QueryWatcher::class => [
            'enabled' => true,
            'ignore_packages' => false,
            'slow' => 20,
        ],
        Watchers\ExceptionWatcher::class => true,
        Watchers\LogWatcher::class => ['enabled' => true, 'level' => 'error'],
        Watchers\ModelWatcher::class => ['enabled' => true, 'events' => ['eloquent.retrieved: *'], 'hydrations' => true],
        Watchers\ClientRequestWatcher::class => ['enabled' => true, 'ignore_hosts' => []],
        Watchers\BatchWatcher::class => false,
        Watchers\CacheWatcher::class => false,
        Watchers\CommandWatcher::class => false,
        Watchers\DumpWatcher::class => false,
        Watchers\EventWatcher::class => false,
        Watchers\GateWatcher::class => false,
        Watchers\JobWatcher::class => false,
        Watchers\MailWatcher::class => false,
        Watchers\NotificationWatcher::class => false,
        Watchers\RedisWatcher::class => false,
        Watchers\ScheduleWatcher::class => false,
        Watchers\ViewWatcher::class => false,
    ],
];
