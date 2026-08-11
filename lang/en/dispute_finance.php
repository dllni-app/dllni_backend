<?php

declare(strict_types=1);

return [
    'action' => [
        'label' => 'Deduct from worker',
        'heading' => 'Apply a financial penalty to the worker',
        'description' => 'The amount is deducted from the worker deposit first. Any uncovered amount becomes outstanding debt. A penalty cannot be applied twice to the same dispute.',
        'submit' => 'Apply deduction',
        'success_title' => 'Financial penalty applied',
        'success_body' => ':amount :currency was charged to the worker financial account and linked to this dispute.',
        'error_title' => 'Unable to apply financial penalty',
        'already_applied' => 'A financial penalty has already been applied to this dispute.',
    ],
    'fields' => [
        'worker' => 'Worker',
        'amount' => 'Penalty amount',
        'amount_helper' => 'The charge consumes the deposit first, then records any remaining amount as debt.',
        'notes' => 'Deduction reason / notes',
        'keep_frozen' => 'Keep worker earnings frozen',
        'keep_frozen_helper' => 'Enable this when the worker earnings must remain frozen after the penalty is recorded.',
        'transaction_reference' => 'Financial transaction reference',
        'applied_by' => 'Applied by',
        'applied_at' => 'Applied at',
    ],
    'section' => 'Dispute financial decision',
    'transaction_note' => 'Penalty for dispute :ticket',
    'validation' => [
        'amount_positive' => 'The penalty amount must be greater than zero.',
        'already_applied' => 'A financial penalty has already been applied to this dispute.',
        'invalid_worker' => 'The selected worker is not assigned to this booking.',
    ],
];
