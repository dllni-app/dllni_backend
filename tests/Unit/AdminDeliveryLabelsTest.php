<?php

declare(strict_types=1);

use App\Filament\Support\AdminDeliveryLabels;

it('translates delivery operational keys for Arabic admin views', function (): void {
    app()->setLocale('ar');

    expect(AdminDeliveryLabels::orderStatus('in_progress'))->toBe('قيد التنفيذ')
        ->and(AdminDeliveryLabels::attemptStatus('timed_out'))->toBe('انتهى الوقت')
        ->and(AdminDeliveryLabels::driverAvailability('busy'))->toBe('مشغول')
        ->and(AdminDeliveryLabels::vehicleType('motorbike'))->toBe('دراجة نارية')
        ->and(AdminDeliveryLabels::dispatchPhase('radius'))->toBe('بحث حسب النطاق الجغرافي')
        ->and(AdminDeliveryLabels::failureCode('WRONG_ADDRESS'))->toBe('العنوان غير صحيح')
        ->and(AdminDeliveryLabels::merchantStatus('preparing', 'restaurant_order'))->toBe('قيد التحضير')
        ->and(AdminDeliveryLabels::merchantStatus('ready_for_pickup', 'supermarket_order'))->toBe('جاهز للاستلام')
        ->and(AdminDeliveryLabels::note('No eligible drivers are currently available.'))->toBe('لا يوجد مناديب مؤهلون متاحون حالياً.')
        ->and(AdminDeliveryLabels::note('No drivers in current radius; expanding search.'))->toBe('لا يوجد مناديب ضمن نطاق البحث الحالي؛ جارٍ توسيع النطاق.');
});

it('keeps unknown delivery values visible instead of hiding operational data', function (): void {
    app()->setLocale('ar');

    expect(AdminDeliveryLabels::orderStatus('custom_state'))->toBe('custom_state')
        ->and(AdminDeliveryLabels::dispatchPhase('custom_phase'))->toBe('custom_phase')
        ->and(AdminDeliveryLabels::failureCode('CUSTOM_CODE'))->toBe('CUSTOM_CODE')
        ->and(AdminDeliveryLabels::note('ملاحظة مخصصة'))->toBe('ملاحظة مخصصة');
});
