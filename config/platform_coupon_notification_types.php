<?php

declare(strict_types=1);

return [
    'types' => [
        'marketing.coupon.available' => [
            'legacy_type' => 'coupon_available',
            'module' => 'marketing',
            'category' => 'offers',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'كوبون جديد متاح لك',
                    'body' => ':description استخدم الرمز :coupon_code.',
                ],
                'en' => [
                    'title' => 'A new coupon is available',
                    'body' => ':description Use code :coupon_code.',
                ],
            ],
        ],
    ],
];
