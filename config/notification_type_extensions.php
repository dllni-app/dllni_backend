<?php

declare(strict_types=1);

return [
    'types' => [
        'cleaning.booking.time_extension_accepted' => [
            'legacy_type' => 'time_extension_accepted',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تم قبول تمديد الوقت',
                    'body' => 'تم قبول تمديد الوقت لحجز التنظيف رقم :booking_number.',
                ],
                'en' => [
                    'title' => 'Time extension accepted',
                    'body' => 'The time extension was accepted for cleaning booking :booking_number.',
                ],
            ],
        ],
        'cleaning.booking.time_extension_rejected' => [
            'legacy_type' => 'time_extension_rejected',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تم رفض تمديد الوقت',
                    'body' => 'تم رفض تمديد الوقت لحجز التنظيف رقم :booking_number.',
                ],
                'en' => [
                    'title' => 'Time extension rejected',
                    'body' => 'The time extension was rejected for cleaning booking :booking_number.',
                ],
            ],
        ],
    ],
];
