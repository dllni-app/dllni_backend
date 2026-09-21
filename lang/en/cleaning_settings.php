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
        'description' => 'Set the base price, minimum order price, pricing unit, deep-cleaning multiplier, and cleaning time for every room type and size.',
        'base_unit_price' => 'Base unit price',
        'base_unit_price_hint' => 'The base amount multiplied by each room pricing unit.',
        'minimum_order_price' => 'Minimum cleaning order price',
        'minimum_order_price_hint' => 'If the summed room price is lower than this value, this value becomes the booking base price. The default is 150 and can be changed from the dashboard.',
        'deep_multiplier' => 'Default deep cleaning multiplier',
        'deep_multiplier_hint' => 'Legacy fallback; each room type and size can now have its own multiplier.',
        'room_size' => 'Room size',
        'room_deep_multiplier' => 'Deep cleaning multiplier',
        'pricing_unit' => 'Pricing unit',
        'regular_minutes' => 'Regular cleaning time (minutes)',
        'deep_minutes' => 'Deep cleaning time (minutes)',
        'formula_hint' => 'Room price = base unit price × pricing unit × that room\'s deep-cleaning multiplier when deep cleaning is selected. After room prices are summed, the minimum order price is applied when the sum is lower.',
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
