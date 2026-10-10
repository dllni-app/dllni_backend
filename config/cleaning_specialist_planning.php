<?php

declare(strict_types=1);

return [
    // Fairness is based on successfully accepted specialist bookings, not invitations
    // or arbitrary ratings. The lower the recent workload, the higher the priority.
    'fairness_lookback_days' => max(1, (int) env('CLEANING_SPECIALIST_FAIRNESS_DAYS', 30)),
    'fairness_enabled' => (bool) env('CLEANING_SPECIALIST_FAIRNESS_ENABLED', true),
    'candidate_limit' => max(50, (int) env('CLEANING_SPECIALIST_FAIRNESS_CANDIDATE_LIMIT', 500)),
    'duration_per_quantity' => (bool) env('CLEANING_SPECIALIST_DURATION_PER_QUANTITY', true),
];
