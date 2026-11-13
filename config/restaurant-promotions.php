<?php

declare(strict_types=1);

return [
    // Enable only after cart, checkout, and both Flutter clients pass the release gate.
    'enabled' => env('RESTAURANT_PROMOTIONS_ENABLED', false),
];
