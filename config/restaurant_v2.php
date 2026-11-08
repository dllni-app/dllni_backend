<?php

declare(strict_types=1);

return [
    'enabled' => env('RESTAURANT_V2_ENABLED', env('APP_ENV', 'production') === 'local'),
    // Restaurant operating-hour rows have no timezone column. Restaurant V2
    // therefore uses an explicit local market timezone without changing the
    // platform's global UTC/application timezone.
    'timezone' => env('RESTAURANT_V2_TIMEZONE', 'Asia/Damascus'),
];
