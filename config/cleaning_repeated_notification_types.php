<?php

declare(strict_types=1);

return [
    'types' => [
        'cleaning.booking.worker_security_code_issue_reminder' => [
            'legacy_type' => 'cleaning_worker_security_code_issue_reminder',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'أنشئ رمز الأمان',
                    'body' => 'وصلت إلى الحجز رقم :booking_number. أنشئ رمز الأمان وشاركه مع العميل لبدء العمل.',
                ],
                'en' => [
                    'title' => 'Generate the security code',
                    'body' => 'You arrived for booking :booking_number. Generate and share the security code to start work.',
                ],
            ],
        ],
        'cleaning.booking.customer_completion_action_reminder' => [
            'legacy_type' => 'cleaning_customer_completion_action_reminder',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'بانتظار تأكيد انتهاء العمل',
                    'body' => 'ينتظر الحجز رقم :booking_number قرارك. أكد الانتهاء أو ارفضه أو اطلب تمديد الوقت.',
                ],
                'en' => [
                    'title' => 'Completion confirmation required',
                    'body' => 'Booking :booking_number is waiting for your decision. Confirm, reject, or request more time.',
                ],
            ],
        ],
        'cleaning.booking.worker_extension_response_reminder' => [
            'legacy_type' => 'cleaning_worker_extension_response_reminder',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'طلب تمديد بانتظار ردك',
                    'body' => 'طلب العميل تمديد وقت الحجز رقم :booking_number. وافق على الطلب أو ارفضه.',
                ],
                'en' => [
                    'title' => 'Extension request needs your response',
                    'body' => 'The customer requested more time for booking :booking_number. Accept or reject the request.',
                ],
            ],
        ],
    ],
];
