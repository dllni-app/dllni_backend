<?php

declare(strict_types=1);

return [
    // Explicit environment settings can still disable promotions during a rollback.
    'enabled' => env('RESTAURANT_PROMOTIONS_ENABLED', true),
];
