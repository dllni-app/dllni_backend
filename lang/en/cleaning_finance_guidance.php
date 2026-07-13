<?php

declare(strict_types=1);

return [
    'title' => 'Transaction type guide',
    'transactions_page_subtitle' => 'A ledger of worker balance movements. The transaction type explains its effect on the security deposit or outstanding debt.',
    'report_page_subtitle' => 'A financial summary of deposits, debts, settlements, and refunds, with the effect of each transaction type explained.',
    'navigation_tooltip' => 'View all worker financial movements and understand how each transaction type affects balances and debt.',
    'intro' => 'Use this guide to understand why each financial movement appears and how it affects the worker account.',
    'compact' => 'Deposits increase the security-deposit balance, debt records an amount owed by the worker, settlements reduce debt, refunds reduce the held deposit, admin fees record amounts owed to administration, and adjustments correct balances manually.',
    'select_helper' => 'Choose the type that matches the transaction’s actual financial effect.',
    'placeholders' => [
        'type' => 'Select a transaction type',
    ],
    'types' => [
        'deposit' => [
            'label' => 'Deposit',
            'description' => 'Adds funds to the worker security-deposit balance and increases the available balance.',
        ],
        'debt' => [
            'label' => 'Debt',
            'description' => 'Records an amount owed by the worker to administration and increases outstanding debt.',
        ],
        'settlement' => [
            'label' => 'Settlement',
            'description' => 'Records a payment from the worker that reduces outstanding debt.',
        ],
        'refund' => [
            'label' => 'Refund',
            'description' => 'Returns money from the security deposit to the worker and reduces the held balance.',
        ],
        'admin_fee' => [
            'label' => 'Admin commission',
            'description' => 'Records commission related to a booking as an amount owed to administration.',
        ],
        'adjustment' => [
            'label' => 'Manual adjustment',
            'description' => 'Applies an administrative balance correction, either a credit or a debit.',
        ],
        'withdrawal' => [
            'label' => 'Legacy refund',
            'description' => 'A legacy refund record that is now treated as a refund.',
        ],
    ],
];
