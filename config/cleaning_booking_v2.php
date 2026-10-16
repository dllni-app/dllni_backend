<?php

declare(strict_types=1);

return [
    'timezone' => env('CLEANING_SERVICE_TIMEZONE', 'Asia/Damascus'),
    'quote_ttl_minutes' => 15,
    'max_workers' => 20,
    'max_duration_minutes' => 480,
];
