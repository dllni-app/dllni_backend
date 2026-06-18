<?php

declare(strict_types=1);

return [
    'name' => 'Cleaning',
    'trust' => [
        'booking_cancel_penalty' => (int) env('CLEANING_WORKER_BOOKING_CANCEL_PENALTY', 10),
        'reject_after_accept_penalty' => (int) env('CLEANING_WORKER_REJECT_AFTER_ACCEPT_PENALTY', 10),
    ],
];
