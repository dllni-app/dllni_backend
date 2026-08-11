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
        'cleaning.booking.customer_upcoming_start_reminder' => [
            'legacy_type' => 'cleaning_customer_upcoming_start_reminder',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تذكير بموعد التنظيف',
                    'body' => 'سيبدأ حجز التنظيف رقم :booking_number الساعة :scheduled_time. يرجى الاستعداد لاستقبال فريق العمل.',
                ],
                'en' => [
                    'title' => 'Cleaning booking reminder',
                    'body' => 'Cleaning booking :booking_number starts at :scheduled_time. Please be ready to receive the team.',
                ],
            ],
        ],
        'cleaning.booking.worker_upcoming_start_reminder' => [
            'legacy_type' => 'cleaning_worker_upcoming_start_reminder',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'لديك حجز تنظيف قريب',
                    'body' => 'سيبدأ الحجز رقم :booking_number الساعة :scheduled_time. راجع التفاصيل واستعد للانطلاق.',
                ],
                'en' => [
                    'title' => 'Upcoming cleaning booking',
                    'body' => 'Booking :booking_number starts at :scheduled_time. Review the details and prepare to travel.',
                ],
            ],
        ],
        'cleaning.booking.worker_start_travel_reminder' => [
            'legacy_type' => 'cleaning_worker_start_travel_reminder',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'حان وقت الانطلاق',
                    'body' => 'تبقى وقت قصير على الحجز رقم :booking_number. ابدأ الرحلة من داخل الطلب.',
                ],
                'en' => [
                    'title' => 'Time to start travelling',
                    'body' => 'Booking :booking_number starts soon. Start travel from the booking details.',
                ],
            ],
        ],
        'cleaning.booking.worker_start_travel_warning' => [
            'legacy_type' => 'cleaning_worker_start_travel_warning',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تحذير: لم تبدأ الرحلة',
                    'body' => 'الحجز رقم :booking_number سيبدأ الساعة :scheduled_time ولم تسجل بدء الرحلة بعد.',
                ],
                'en' => [
                    'title' => 'Warning: travel not started',
                    'body' => 'Booking :booking_number starts at :scheduled_time and travel has not been started yet.',
                ],
            ],
        ],
        'cleaning.booking.worker_arrival_warning' => [
            'legacy_type' => 'cleaning_worker_arrival_warning',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'موعد الحجز بدأ',
                    'body' => 'بدأ موعد الحجز رقم :booking_number. عند الوصول سجل وصولك من داخل الطلب.',
                ],
                'en' => [
                    'title' => 'Booking start time reached',
                    'body' => 'Booking :booking_number has started. Mark your arrival from the booking details.',
                ],
            ],
        ],
        'cleaning.booking.worker_arrival_critical_warning' => [
            'legacy_type' => 'cleaning_worker_arrival_critical_warning',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تحذير عاجل: تأخر الوصول',
                    'body' => 'تأخرت عن الحجز رقم :booking_number ولم تسجل الوصول. افتح الطلب واتخذ الإجراء فوراً.',
                ],
                'en' => [
                    'title' => 'Urgent warning: arrival overdue',
                    'body' => 'You are late for booking :booking_number and arrival is not recorded. Open the booking now.',
                ],
            ],
        ],
        'cleaning.booking.customer_verification_reminder' => [
            'legacy_type' => 'cleaning_customer_verification_reminder',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'يرجى تأكيد وصول العامل',
                    'body' => 'وصل فريق الحجز رقم :booking_number. أدخل رمز الأمان لبدء العمل.',
                ],
                'en' => [
                    'title' => 'Confirm the worker arrival',
                    'body' => 'The team for booking :booking_number has arrived. Enter the security code to start work.',
                ],
            ],
        ],
        'cleaning.booking.customer_verification_warning' => [
            'legacy_type' => 'cleaning_customer_verification_warning',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'رمز الأمان على وشك الانتهاء',
                    'body' => 'أكد بدء الحجز رقم :booking_number قبل انتهاء صلاحية رمز الأمان.',
                ],
                'en' => [
                    'title' => 'Security code expires soon',
                    'body' => 'Confirm the start of booking :booking_number before the security code expires.',
                ],
            ],
        ],
        'cleaning.booking.worker_start_confirmation_reminder' => [
            'legacy_type' => 'cleaning_worker_start_confirmation_reminder',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تم تأكيد رمز الأمان',
                    'body' => 'أكد العميل رمز الحجز رقم :booking_number. أكد بدء العمل من داخل الطلب.',
                ],
                'en' => [
                    'title' => 'Security code confirmed',
                    'body' => 'The customer confirmed booking :booking_number. Confirm and start work from the booking.',
                ],
            ],
        ],
        'cleaning.booking.worker_start_confirmation_warning' => [
            'legacy_type' => 'cleaning_worker_start_confirmation_warning',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تحذير: لم تبدأ العمل',
                    'body' => 'أكد العميل رمز الحجز رقم :booking_number لكنك لم تؤكد بدء العمل بعد.',
                ],
                'en' => [
                    'title' => 'Warning: work not started',
                    'body' => 'The customer confirmed booking :booking_number, but you have not confirmed the work start.',
                ],
            ],
        ],
        'cleaning.booking.team_incomplete_warning' => [
            'legacy_type' => 'cleaning_team_incomplete_warning',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تنبيه بخصوص فريق الحجز',
                    'body' => 'لم يكتمل فريق الحجز رقم :booking_number حتى الآن. افتح الطلب لمراجعة الحالة.',
                ],
                'en' => [
                    'title' => 'Booking team incomplete',
                    'body' => 'The team for booking :booking_number is not complete yet. Open the order to review its status.',
                ],
            ],
        ],
    ],
];
