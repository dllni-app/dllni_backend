<?php

declare(strict_types=1);

return [
    'nav_label' => 'Settings',
    'title' => 'Settings',
    'tooltip' => 'Manage cleaning pricing, room times, commissions, travel costs, time billing, and worker finance settings.',
    'subheading' => 'Manage pricing for every room type and size, commissions, travel costs, time billing, and worker finance settings.',
    'saved' => 'Cleaning settings saved successfully.',

    'pricing' => [
        'section' => 'Room pricing and time',
        'description' => 'Set the pricing unit and cleaning time for every room type and size used by the customer app.',
        'base_unit_price' => 'Base unit price',
        'base_unit_price_hint' => 'The base amount multiplied by each room pricing unit.',
        'deep_multiplier' => 'Deep cleaning multiplier',
        'deep_multiplier_hint' => 'Applied to the room price when deep cleaning is selected.',
        'room_size' => 'Room size',
        'pricing_unit' => 'Pricing unit',
        'regular_minutes' => 'Regular cleaning time (minutes)',
        'deep_minutes' => 'Deep cleaning time (minutes)',
        'formula_hint' => 'Room price = base unit price × pricing unit × cleaning mode multiplier. Total time is the sum of room times by type, size, and quantity.',
    ],

    'room_types' => [
        'bedroom' => 'Bedroom',
        'bathroom' => 'Bathroom',
        'kitchen' => 'Kitchen',
        'living_room' => 'Living room',
        'balcony' => 'Balcony',
        'corridor' => 'Corridor',
        'shed' => 'Shed',
    ],

    'room_sizes' => [
        'small' => 'Small',
        'medium' => 'Medium',
        'large' => 'Large',
    ],

    'validation' => [
        'room_matrix' => 'Settings are required for every room type available in the app.',
        'room_sizes' => 'Settings are required for small, medium, and large sizes.',
    ],
];
