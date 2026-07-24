<?php

declare(strict_types=1);

return [
    'types' => [
        'cleaning.financial_penalty.applied' => [
            'legacy_type' => 'cleaning_financial_penalty_applied',
            'module' => 'cleaning',
            'category' => 'system',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'غرامة مالية',
                    'body' => 'تم فرض غرامة مالية عليك بقيمة :amount بسبب إنهاء الطلب رقم :booking_number في وقت متأخر.',
                ],
                'en' => [
                    'title' => 'Financial penalty',
                    'body' => 'A financial penalty of :amount :currency was applied for cancelling booking :booking_number.',
                ],
            ],
        ],
    ],
];
