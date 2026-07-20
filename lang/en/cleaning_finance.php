<?php

declare(strict_types=1);

return [
    'debt' => [
        'label' => 'Add administration loan',
        'description' => 'Adds the amount to the worker deposit balance and clearly marks it as administration-funded debt. It does not increase indebtedness or consume the indebtedness limit.',
        'success' => 'Administration loan added to the deposit balance successfully',
    ],
    'settlement' => [
        'description' => 'A settlement reduces indebtedness created when platform charges exceed the deposit balance.',
    ],
    'fields' => [
        'amount' => 'Amount',
        'positive_amount_hint' => 'Enter an amount greater than zero.',
        'debt_settled_amount' => 'Administration loan recovered',
    ],
    'types' => [
        'debt' => 'Administration loan',
    ],
    'references' => [
        'admin_debt' => 'Legacy administration loan',
        'admin_manual_debt' => 'Legacy loan from transaction screen',
        'admin_deposit_loan' => 'Administration loan added to deposit',
        'automatic_admin_commission' => 'Indebtedness from administration commission',
    ],
    'report' => [
        'outstanding_admin_due' => 'Outstanding administration due (loan + indebtedness)',
    ],
];
