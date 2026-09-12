<?php

declare(strict_types=1);

return [
    'late_grace_minutes' => (int) env('CLEANING_RECURRING_LATE_GRACE_MINUTES', 15),
    'no_travel_grace_minutes' => (int) env('CLEANING_RECURRING_NO_TRAVEL_GRACE_MINUTES', 30),
];
