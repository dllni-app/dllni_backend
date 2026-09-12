<?php

declare(strict_types=1);

return [
    'types' => [
        'cleaning.recurring.change_requested' => [
            'legacy_type' => 'recurring_change_requested',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'طلب موافقة على تعديل الحجز الدوري',
                    'body' => 'يوجد تعديل مقترح على الحجز رقم :booking_number ويحتاج إلى قرارك.',
                ],
                'en' => [
                    'title' => 'Recurring booking change approval',
                    'body' => 'A proposed change to booking :booking_number requires your decision.',
                ],
            ],
        ],
        'cleaning.booking.recurring_coverage_progress' => [
            'legacy_type' => 'recurring_coverage_progress',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تحديث تغطية الزيارات الدورية',
                    'body' => 'تمت تغطية :covered_sessions من أصل :total_sessions زيارة دورية، وما يزال :remaining_seats مقعد عامل بحاجة للتغطية.',
                ],
                'en' => [
                    'title' => 'Recurring visit coverage update',
                    'body' => ':covered_sessions of :total_sessions recurring visits are covered, with :remaining_seats worker seats still open.',
                ],
            ],
        ],
        'cleaning.booking.recurring_coverage_complete' => [
            'legacy_type' => 'recurring_coverage_complete',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'اكتملت تغطية الزيارات الدورية',
                    'body' => 'اكتملت تغطية جميع الزيارات الدورية القادمة للحجز رقم :booking_number.',
                ],
                'en' => [
                    'title' => 'Recurring visits fully covered',
                    'body' => 'All upcoming recurring visits for booking :booking_number are now fully covered.',
                ],
            ],
        ],
    ],
];
