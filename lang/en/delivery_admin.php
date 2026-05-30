<?php

declare(strict_types=1);

return [
    'nav_group' => 'Delivery',
    'companies' => [
        'nav_label' => 'Delivery companies',
        'model' => 'Delivery company',
        'plural' => 'Delivery companies',
        'sections' => [
            'profile' => 'Company profile',
        ],
        'fields' => [
            'name' => 'Company name',
            'legal_name' => 'Legal name',
            'owner' => 'Owner',
            'phone' => 'Phone',
            'email' => 'Email',
            'address' => 'Address',
        ],
    ],
    'collection' => [
        'action' => 'Record collection',
        'success' => 'Collection recorded successfully.',
        'fields' => [
            'amount' => 'Collection amount',
            'note' => 'Note (optional)',
        ],
    ],
];
