<?php

it('displays the partially completed cleaning booking status in Arabic', function (): void {
    expect(trans('cleaning_admin.enums.cleaning_booking_status.partially_completed', [], 'ar'))
        ->toBe('مكتمل جزئياً')
        ->and(trans('cleaning_admin.enums.cleaning_booking_status.partially_completed', [], 'en'))
        ->toBe('Partially completed');
});
