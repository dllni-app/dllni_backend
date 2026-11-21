<?php

declare(strict_types=1);

return [
    'nav' => 'مراجعة التزام العاملين',
    'case' => 'حالة مراجعة',
    'cases' => 'حالات المراجعة',
    'overview' => [
        'enrolled' => 'العاملون المسجلون',
        'recent_contact' => 'تواصل حديث',
        'stale' => 'بيانات قديمة',
        'stale_description' => 'آخر تواصل أقدم من 15 دقيقة',
        'unknown' => 'الحالة غير معروفة',
        'unresolved' => 'حالات بانتظار المراجعة',
    ],
    'sections' => [
        'case' => 'تفاصيل الحالة',
        'audit' => 'سجل المراجعة',
    ],
    'fields' => [
        'case_id' => 'رقم الحالة',
        'worker' => 'العامل',
        'status' => 'الحالة',
        'reason' => 'سبب الإحالة',
        'observation_start' => 'بداية المدة المرصودة',
        'observation_end' => 'نهاية المدة المرصودة',
        'samples' => 'عدد العينات',
        'opened_at' => 'تاريخ إنشاء الحالة',
        'booking_reference' => 'مرجع الحجز السابق',
        'session_reference' => 'مرجع الجلسة',
        'distance_band' => 'نطاق المسافة',
        'accuracy_band' => 'نطاق الدقة',
        'rule_version' => 'إصدار قاعدة المطابقة',
        'claimed_by' => 'المراجع المسؤول',
        'event' => 'الإجراء',
        'actor' => 'المستخدم',
        'from_status' => 'من الحالة',
        'to_status' => 'إلى الحالة',
        'review_reason' => 'سبب المراجعة',
        'reference' => 'مرجع الدليل',
        'independent_evidence' => 'مرجع دليل مستقل غير قائم على الموقع',
        'worker_response_opportunity' => 'تاريخ إتاحة الرد للعامل',
        'event_at' => 'التاريخ',
    ],
    'status' => [
        'new' => 'جديدة',
        'under_review' => 'قيد المراجعة',
        'explained' => 'لها تفسير',
        'inconclusive' => 'غير حاسمة',
        'confirmed_violation' => 'مؤكدة بأدلة مستقلة',
    ],
    'actions' => [
        'claim' => 'استلام للمراجعة',
        'explained' => 'تسجيل تفسير',
        'inconclusive' => 'إغلاق دون نتيجة حاسمة',
        'confirm' => 'تأكيد بمراجعة مستقلة',
    ],
    'help' => [
        'independent_evidence' => 'أدخل مرجعاً لدليل مستقل عن بيانات الموقع. قرب العامل من الموقع وحده لا يكفي للتأكيد.',
    ],
    'notifications' => [
        'claimed' => 'تم استلام الحالة للمراجعة.',
        'resolved' => 'تم تسجيل نتيجة المراجعة.',
        'confirmed' => 'تم تأكيد الحالة بعد مراجعة مستقلة.',
    ],
];
