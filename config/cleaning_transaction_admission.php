<?php

declare(strict_types=1);

return [
    // Experimental P3 root only. Leave disabled until production-writer
    // closure, independent MySQL two-process races and release review pass.
    // An explicit opt-in keeps this candidate from silently changing the
    // established financial API while neighboring writers remain unadmitted.
    'admin_deposit_pilot_enabled' => env('CLEANING_P3_ADMIN_DEPOSIT_PILOT', false),
];
