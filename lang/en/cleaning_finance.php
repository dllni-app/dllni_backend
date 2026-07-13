<?php

declare(strict_types=1);

return [
    'debt' => [
        'label' => 'Add debt',
        'description' => 'Credits the worker with platform-funded balance so they can receive orders, while recording the amount as debt owed to administration.',
        'success' => 'Debt added and worker eligibility updated successfully',
    ],
    'settlement' => [
        'description' => 'A settlement pays administration-funded debt first, then outstanding administration commission.',
    ],
    'fields' => [
        'amount' => 'Amount',
        'positive_amount_hint' => 'Enter an amount greater than zero.',
        'debt_settled_amount' => 'Debt portion settled',
    ],
    'types' => [
        'debt' => 'Debt',
    ],
    'references' => [
        'admin_debt' => 'Debt added by administration',
        'admin_manual_debt' => 'Debt added from transaction screen',
        'automatic_admin_commission' => 'Automatically recorded administration debt',
    ],
    'report' => [
        'outstanding_admin_due' => 'Outstanding administration due (commission + debt)',
    ],
];
