<?php

declare(strict_types=1);

return [
    'name' => 'Cleaning',
    'trust' => [
        'booking_cancel_penalty' => (int) env('CLEANING_WORKER_BOOKING_CANCEL_PENALTY', 10),
    ],
];
