<?php

declare(strict_types=1);

return [
    'nav' => 'Worker Compliance Review',
    'case' => 'Review case',
    'cases' => 'Review cases',
    'overview' => [
        'enrolled' => 'Enrolled workers',
        'recent_contact' => 'Recent contact',
        'stale' => 'Stale telemetry',
        'stale_description' => 'Last contact was more than 15 minutes ago',
        'unknown' => 'Unknown health',
        'unresolved' => 'Cases awaiting review',
    ],
    'sections' => [
        'case' => 'Case details',
        'audit' => 'Review history',
    ],
    'fields' => [
        'case_id' => 'Case ID',
        'worker' => 'Worker',
        'status' => 'Status',
        'reason' => 'Referral reason',
        'observation_start' => 'Observation start',
        'observation_end' => 'Observation end',
        'samples' => 'Sample count',
        'opened_at' => 'Opened at',
        'booking_reference' => 'Historical booking reference',
        'session_reference' => 'Session reference',
        'distance_band' => 'Distance band',
        'accuracy_band' => 'Accuracy band',
        'rule_version' => 'Matching rule version',
        'claimed_by' => 'Assigned reviewer',
        'event' => 'Action',
        'actor' => 'User',
        'from_status' => 'From status',
        'to_status' => 'To status',
        'review_reason' => 'Review reason',
        'reference' => 'Evidence reference',
        'independent_evidence' => 'Independent non-location evidence reference',
        'worker_response_opportunity' => 'When the worker could respond',
        'event_at' => 'Date',
    ],
    'status' => [
        'new' => 'New',
        'under_review' => 'Under review',
        'explained' => 'Explained',
        'inconclusive' => 'Inconclusive',
        'confirmed_violation' => 'Confirmed with independent evidence',
    ],
    'actions' => [
        'claim' => 'Claim for review',
        'explained' => 'Record explanation',
        'inconclusive' => 'Close as inconclusive',
        'confirm' => 'Confirm with independent review',
    ],
    'help' => [
        'independent_evidence' => 'Enter a reference to evidence independent of location data. Proximity alone cannot support confirmation.',
    ],
    'notifications' => [
        'claimed' => 'Case assigned for review.',
        'resolved' => 'Review outcome recorded.',
        'confirmed' => 'Case confirmed after an independent review.',
    ],
];
