<?php

declare(strict_types=1);

return [
    'default_locale' => 'ar',
    'fallback_locale' => 'en',
    'module_icons' => [
        'cleaning' => '/images/notifications/cleaning.svg',
        'supermarket' => '/images/notifications/supermarket.svg',
        'restaurant' => '/images/notifications/restaurant.svg',
        'delivery' => '/images/notifications/delivery.svg',
    ],
    'types' => [
        'cleaning.booking.new_order_request' => [
            'legacy_type' => 'new_order',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'طلب جديد',
                    'body' => 'طلب تنظيف جديد: :booking_number. قم بقبوله أو رفضه خلال الوقت المحدد.',
                ],
                'en' => [
                    'title' => 'New order request',
                    'body' => 'A new cleaning booking :booking_number is waiting for your response.',
                ],
            ],
        ],
        'cleaning.booking.extension_request' => [
            'legacy_type' => 'extension_request',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'طلب تمديد وقت',
                    'body' => 'العميل يطلب تمديد وقت الحجز. قم بقبول أو رفض الطلب.',
                ],
                'en' => [
                    'title' => 'Extension request',
                    'body' => 'The customer requested an extension for this booking.',
                ],
            ],
        ],
        'cleaning.booking.dispute_opened' => [
            'legacy_type' => 'dispute_opened',
            'module' => 'cleaning',
            'category' => 'system',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'نزاع مفتوح',
                    'body' => 'تم فتح نزاع على إحدى حجوزاتك. يرجى الرد على الشكوى.',
                ],
                'en' => [
                    'title' => 'Dispute opened',
                    'body' => 'A dispute was opened for one of your bookings. Please review it.',
                ],
            ],
        ],
        'cleaning.booking.created' => [
            'legacy_type' => 'cleaning_booking_created',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تم إنشاء طلب تنظيف',
                    'body' => 'تم إنشاء طلب التنظيف رقم :booking_number.',
                ],
                'en' => [
                    'title' => 'Cleaning order created',
                    'body' => 'Cleaning booking :booking_number has been created.',
                ],
            ],
        ],
        'cleaning.booking.updated' => [
            'legacy_type' => 'cleaning_booking_updated',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تم تحديث طلب التنظيف',
                    'body' => 'تم تحديث طلب التنظيف رقم :booking_number إلى الحالة :status.',
                ],
                'en' => [
                    'title' => 'Cleaning order updated',
                    'body' => 'Cleaning booking :booking_number was updated to status :status.',
                ],
            ],
        ],
        'cleaning.booking.worker_assigned' => [
            'legacy_type' => 'worker_assigned',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'en' => [
                    'title' => 'Worker assigned',
                    'body' => 'A worker has been assigned to booking :booking_number.',
                ],
            ],
        ],
        'cleaning.booking.worker_confirmed' => [
            'legacy_type' => 'worker_confirmed',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'en' => [
                    'title' => 'Worker confirmed',
                    'body' => 'A worker confirmed booking :booking_number.',
                ],
            ],
        ],
        'cleaning.booking.worker_started_travel' => [
            'legacy_type' => 'worker_started_travel',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'en' => [
                    'title' => 'Worker is on the way',
                    'body' => 'The worker started travel for booking :booking_number.',
                ],
            ],
        ],
        'cleaning.booking.worker_arrived' => [
            'legacy_type' => 'worker_arrived',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'en' => [
                    'title' => 'Worker arrived',
                    'body' => 'The worker arrived for booking :booking_number.',
                ],
            ],
        ],
        'cleaning.booking.start_verified' => [
            'legacy_type' => 'start_verified',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'en' => [
                    'title' => 'Arrival verified',
                    'body' => 'Customer verified start for booking :booking_number.',
                ],
            ],
        ],
        'cleaning.booking.completion_requested' => [
            'legacy_type' => 'completion_requested',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'en' => [
                    'title' => 'Completion requested',
                    'body' => 'Worker requested completion confirmation for booking :booking_number.',
                ],
            ],
        ],
        'cleaning.booking.completion_approved' => [
            'legacy_type' => 'completion_approved',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'en' => [
                    'title' => 'Completion approved',
                    'body' => 'Customer approved completion for booking :booking_number.',
                ],
            ],
        ],
        'cleaning.booking.completion_rejected' => [
            'legacy_type' => 'completion_rejected',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'en' => [
                    'title' => 'Completion rejected',
                    'body' => 'Customer rejected completion for booking :booking_number.',
                ],
            ],
        ],
        'cleaning.booking.time_extension_requested' => [
            'legacy_type' => 'time_extension_requested',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'en' => [
                    'title' => 'Time extension requested',
                    'body' => 'Customer requested more time for booking :booking_number.',
                ],
            ],
        ],
        'cleaning.booking.order_cancelled' => [
            'legacy_type' => 'order_cancelled',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'en' => [
                    'title' => 'Order cancelled',
                    'body' => 'Cleaning booking :booking_number was cancelled.',
                ],
            ],
        ],
        'cleaning.booking.preferred_worker_unavailable' => [
            'legacy_type' => 'preferred_worker_unavailable',
            'module' => 'cleaning',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'العامل المفضل غير متاح',
                    'body' => 'العامل المفضل غير متاح لطلب التنظيف رقم :booking_number، وسيتم عرض الطلب على عاملين آخرين.',
                ],
                'en' => [
                    'title' => 'Preferred worker unavailable',
                    'body' => 'Your preferred worker is unavailable for booking :booking_number. The request was opened to other workers.',
                ],
            ],
        ],
        'supermarket.smart_list.scheduled_order_sent' => [
            'legacy_type' => 'smart_list_scheduled_order_sent',
            'module' => 'supermarket',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تم إرسال طلب القائمة الذكية',
                    'body' => 'تم إنشاء الطلب رقم :order_number من القائمة :smart_list_name وإرساله للمتجر.',
                ],
                'en' => [
                    'title' => 'Scheduled smart-list order sent',
                    'body' => 'Order :order_number was created from :smart_list_name and sent to the store.',
                ],
            ],
        ],
        'supermarket.smart_list.scheduled_order_failed' => [
            'legacy_type' => 'smart_list_scheduled_order_failed',
            'module' => 'supermarket',
            'category' => 'system',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'فشل تنفيذ الطلب المجدول',
                    'body' => 'تعذر إرسال طلب القائمة :smart_list_name. السبب: :reason',
                ],
                'en' => [
                    'title' => 'Scheduled order failed',
                    'body' => 'Could not send a scheduled order from :smart_list_name. Reason: :reason',
                ],
            ],
        ],
        'supermarket.order.rejected' => [
            'legacy_type' => 'supermarket_order_rejected',
            'module' => 'supermarket',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database'],
            'templates' => [
                'ar' => [
                    'title' => 'تم رفض الطلب',
                    'body' => 'تم رفض طلبك رقم :order_number.',
                ],
                'en' => [
                    'title' => 'Order rejected',
                    'body' => 'Your order :order_number was rejected.',
                ],
            ],
        ],
        'supermarket.store.trust_warning' => [
            'legacy_type' => 'store_trust_warning',
            'module' => 'supermarket',
            'category' => 'system',
            'priority' => 'high',
            'channels' => ['database'],
            'templates' => [
                'ar' => [
                    'title' => 'تحذير مستوى الثقة',
                    'body' => 'انخفض مستوى الثقة للمتجر إلى :trust_score. يرجى مراجعة حالات الرفض الأخيرة.',
                ],
                'en' => [
                    'title' => 'Store trust warning',
                    'body' => 'Store trust score dropped to :trust_score. Please review recent rejections.',
                ],
            ],
        ],
        'supermarket.store.consecutive_rejections_alert' => [
            'legacy_type' => 'consecutive_rejections_alert',
            'module' => 'supermarket',
            'category' => 'system',
            'priority' => 'high',
            'channels' => ['database'],
            'templates' => [
                'ar' => [
                    'title' => 'تنبيه رفض متكرر',
                    'body' => 'تم تسجيل :recent_cancelled_count رفض متتالي للطلبات في متجرك.',
                ],
                'en' => [
                    'title' => 'Consecutive rejections alert',
                    'body' => 'Your store has :recent_cancelled_count consecutive rejected orders.',
                ],
            ],
        ],
        'restaurant.owner.order_created' => [
            'legacy_type' => 'restaurant_owner_order_created',
            'module' => 'restaurant',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database'],
            'templates' => [
                'ar' => [
                    'title' => 'طلب جديد للمطعم',
                    'body' => 'وصل طلب جديد رقم :order_number ويحتاج متابعة.',
                ],
                'en' => [
                    'title' => 'New restaurant order',
                    'body' => 'A new order :order_number needs your attention.',
                ],
            ],
        ],
        'restaurant.owner.order_cancelled' => [
            'legacy_type' => 'restaurant_owner_order_cancelled',
            'module' => 'restaurant',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database'],
            'templates' => [
                'ar' => [
                    'title' => 'تم إلغاء الطلب',
                    'body' => 'تم إلغاء الطلب رقم :order_number.',
                ],
                'en' => [
                    'title' => 'Order cancelled',
                    'body' => 'Order :order_number was cancelled.',
                ],
            ],
        ],
        'restaurant.owner.offer_performance' => [
            'legacy_type' => 'restaurant_owner_offer_performance',
            'module' => 'restaurant',
            'category' => 'offers',
            'priority' => 'normal',
            'channels' => ['database'],
            'templates' => [
                'ar' => [
                    'title' => 'أداء العرض',
                    'body' => 'يوجد تحديث جديد على أداء العرض :offer_name.',
                ],
                'en' => [
                    'title' => 'Offer performance',
                    'body' => 'There is a new update for offer :offer_name performance.',
                ],
            ],
        ],
        'restaurant.owner.system_announcement' => [
            'legacy_type' => 'restaurant_owner_system_announcement',
            'module' => 'restaurant',
            'category' => 'system',
            'priority' => 'normal',
            'channels' => ['database'],
            'templates' => [
                'ar' => [
                    'title' => 'إعلان نظام',
                    'body' => ':announcement',
                ],
                'en' => [
                    'title' => 'System announcement',
                    'body' => ':announcement',
                ],
            ],
        ],
        'user.account.reminder' => [
            'legacy_type' => 'account',
            'module' => 'user',
            'category' => 'system',
            'priority' => 'normal',
            'channels' => ['database'],
            'templates' => [
                'ar' => [
                    'title' => 'تذكير بالحساب',
                    'body' => ':message',
                ],
                'en' => [
                    'title' => 'Account reminder',
                    'body' => ':message',
                ],
            ],
        ],
        'delivery.order.completed' => [
            'legacy_type' => 'delivery_order_completed',
            'module' => 'delivery',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'اكتمل الطلب',
                    'body' => 'اكتمل طلب التوصيل :order_number.',
                ],
                'en' => [
                    'title' => 'Order completed',
                    'body' => 'Delivery order :order_number has been completed.',
                ],
            ],
        ],
        'delivery.order.stopped' => [
            'legacy_type' => 'delivery_order_stopped',
            'module' => 'delivery',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'توقف التوزيع',
                    'body' => 'تعذر إسناد الطلب :order_number. السبب: :reason',
                ],
                'en' => [
                    'title' => 'Dispatch stopped',
                    'body' => 'Delivery order :order_number could not be assigned. Reason: :reason',
                ],
            ],
        ],
        'delivery.order.offer' => [
            'legacy_type' => 'delivery_order_offer',
            'module' => 'delivery',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'عرض توصيل جديد',
                    'body' => 'لديك عرض توصيل للطلب :order_number.',
                ],
                'en' => [
                    'title' => 'New delivery offer',
                    'body' => 'You have a delivery offer for order :order_number.',
                ],
            ],
        ],
        'delivery.order.offer_timed_out' => [
            'legacy_type' => 'delivery_order_offer_timed_out',
            'module' => 'delivery',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'انتهى وقت العرض',
                    'body' => 'انتهى وقت عرض الطلب :order_number.',
                ],
                'en' => [
                    'title' => 'Offer timed out',
                    'body' => 'The offer for order :order_number has expired.',
                ],
            ],
        ],
        'delivery.order.accepted' => [
            'legacy_type' => 'delivery_order_accepted',
            'module' => 'delivery',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تم قبول الطلب',
                    'body' => 'قبل السائق الطلب :order_number.',
                ],
                'en' => [
                    'title' => 'Order accepted',
                    'body' => 'A driver accepted delivery order :order_number.',
                ],
            ],
        ],
        'delivery.order.merchant_preparation_updated' => [
            'legacy_type' => 'delivery_order_merchant_preparation_updated',
            'module' => 'delivery',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تحديث وقت تجهيز الطلب',
                    'body' => 'تم تحديث وقت تجهيز الطلب :order_number.',
                ],
                'en' => [
                    'title' => 'Preparation time updated',
                    'body' => 'The preparation estimate for order :order_number was updated.',
                ],
            ],
        ],
        'delivery.order.merchant_ready' => [
            'legacy_type' => 'delivery_order_merchant_ready',
            'module' => 'delivery',
            'category' => 'orders',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'الطلب جاهز للاستلام',
                    'body' => 'أصبح الطلب :order_number جاهزاً للاستلام.',
                ],
                'en' => [
                    'title' => 'Order ready for pickup',
                    'body' => 'Order :order_number is ready for pickup.',
                ],
            ],
        ],
        'delivery.dispute.opened' => [
            'legacy_type' => 'delivery_dispute_opened',
            'module' => 'delivery',
            'category' => 'system',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'نزاع جديد',
                    'body' => 'تم فتح نزاع :ticket_number على الطلب :order_number.',
                ],
                'en' => [
                    'title' => 'Dispute opened',
                    'body' => 'Dispute :ticket_number was opened for order :order_number.',
                ],
            ],
        ],
        'delivery.dispute.resolved' => [
            'legacy_type' => 'delivery_dispute_resolved',
            'module' => 'delivery',
            'category' => 'system',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تم تحديث النزاع',
                    'body' => 'تم تحديث النزاع :ticket_number للطلب :order_number إلى :status.',
                ],
                'en' => [
                    'title' => 'Dispute updated',
                    'body' => 'Dispute :ticket_number for order :order_number is now :status.',
                ],
            ],
        ],
        'delivery.dispute.rejected' => [
            'legacy_type' => 'delivery_dispute_rejected',
            'module' => 'delivery',
            'category' => 'system',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تم رفض النزاع',
                    'body' => 'تم رفض النزاع :ticket_number للطلب :order_number.',
                ],
                'en' => [
                    'title' => 'Dispute rejected',
                    'body' => 'Dispute :ticket_number for order :order_number was rejected.',
                ],
            ],
        ],
        'delivery.order.started' => [
            'legacy_type' => 'delivery_order_started',
            'module' => 'delivery',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'بدء التوصيل',
                    'body' => 'بدأ السائق توصيل الطلب :order_number.',
                ],
                'en' => [
                    'title' => 'Delivery started',
                    'body' => 'The driver started delivery for order :order_number.',
                ],
            ],
        ],
        'delivery.order.picked_up' => [
            'legacy_type' => 'delivery_order_picked_up',
            'module' => 'delivery',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تم الاستلام',
                    'body' => 'تم استلام الطلب :order_number من نقطة الإرسال.',
                ],
                'en' => [
                    'title' => 'Order picked up',
                    'body' => 'Order :order_number was picked up from the sender.',
                ],
            ],
        ],
        'delivery.order.delivered' => [
            'legacy_type' => 'delivery_order_delivered',
            'module' => 'delivery',
            'category' => 'orders',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تم التسليم',
                    'body' => 'تم تسليم الطلب :order_number إلى المستلم.',
                ],
                'en' => [
                    'title' => 'Order delivered',
                    'body' => 'Order :order_number was delivered to the recipient.',
                ],
            ],
        ],
        'delivery.driver.trust_changed' => [
            'legacy_type' => 'delivery_driver_trust_changed',
            'module' => 'delivery',
            'category' => 'system',
            'priority' => 'normal',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'تحديث نقاط الثقة',
                    'body' => 'نقاط الثقة: :delta (الإجمالي :score).',
                ],
                'en' => [
                    'title' => 'Trust score updated',
                    'body' => 'Trust score changed by :delta (now :score).',
                ],
            ],
        ],
        'delivery.financial.collection_posted' => [
            'legacy_type' => 'delivery_financial_collection_posted',
            'module' => 'delivery',
            'category' => 'system',
            'priority' => 'normal',
            'channels' => ['database'],
            'templates' => [
                'ar' => [
                    'title' => 'تم تسجيل تحصيل',
                    'body' => 'تم تسجيل تحصيل بقيمة :amount لـ :company_name.',
                ],
                'en' => [
                    'title' => 'Collection recorded',
                    'body' => 'A collection of :amount was recorded for :company_name.',
                ],
            ],
        ],
        'delivery.financial.suspended' => [
            'legacy_type' => 'delivery_financial_suspended',
            'module' => 'delivery',
            'category' => 'system',
            'priority' => 'high',
            'channels' => ['database', 'push'],
            'templates' => [
                'ar' => [
                    'title' => 'إيقاف مالي',
                    'body' => 'بلغت :company_name الحد المالي (الرصيد :balance / الحد :limit).',
                ],
                'en' => [
                    'title' => 'Financial suspension',
                    'body' => ':company_name reached the financial limit (balance :balance / limit :limit).',
                ],
            ],
        ],
        'delivery.financial.reactivated' => [
            'legacy_type' => 'delivery_financial_reactivated',
            'module' => 'delivery',
            'category' => 'system',
            'priority' => 'normal',
            'channels' => ['database'],
            'templates' => [
                'ar' => [
                    'title' => 'إعادة تفعيل الحساب المالي',
                    'body' => ':company_name أصبحت دون الحد المالي ويمكنها التوزيع مجدداً.',
                ],
                'en' => [
                    'title' => 'Financial account reactivated',
                    'body' => ':company_name is below the financial limit and can dispatch again.',
                ],
            ],
        ],
    ],
];
