<?php

declare(strict_types=1);

return [
    // Remains off for public booking creation until operational and privacy
    // approval, notification delivery and the payment extension policy are signed off.
    'enabled' => (bool) env('SUPERVISED_TEAM_ENABLED', false),
    // Requires confirmed customer billing and conflict policy before rollout.
    'extension_approval_enabled' => (bool) env('SUPERVISED_TEAM_EXTENSION_APPROVAL_ENABLED', false),
];
