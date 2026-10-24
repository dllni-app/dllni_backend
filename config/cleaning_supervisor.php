<?php

declare(strict_types=1);

return [
    // Enable only after the verification, customer consent, and closure contracts are approved.
    'booking_enabled' => (bool) env('SUPERVISED_TEAM_BOOKING_ENABLED', false),
    'minimum_workers' => 2,
    'maximum_workers' => 20,
];
