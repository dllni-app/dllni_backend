<?php

declare(strict_types=1);

return [
    'nav_group' => 'التوصيل',
    'companies' => [
        'nav_label' => 'شركات التوصيل',
        'model' => 'شركة توصيل',
        'plural' => 'شركات التوصيل',
        'sections' => [
            'profile' => 'ملف الشركة',
        ],
        'fields' => [
            'name' => 'اسم الشركة',
            'legal_name' => 'الاسم القانوني',
            'owner' => 'المالك',
            'phone' => 'الهاتف',
            'email' => 'البريد الإلكتروني',
            'address' => 'العنوان',
        ],
    ],
    'collection' => [
        'action' => 'تسجيل تحصيل',
        'success' => 'تم تسجيل التحصيل بنجاح.',
        'fields' => [
            'amount' => 'مبلغ التحصيل',
            'note' => 'ملاحظة (اختياري)',
        ],
    ],
];
