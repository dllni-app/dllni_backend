<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningBooking;

it('labels regular, deep, and event assistance bookings for the dashboard', function (): void {
    $regular = new CleaningBooking([
        'property_type' => 'apartment',
        'property_details' => ['cleaning_mode' => 'regular'],
    ]);
    $deep = new CleaningBooking([
        'property_type' => 'villa',
        'property_details' => ['cleaning_mode' => 'deep'],
    ]);
    $event = new CleaningBooking([
        'property_type' => 'event_assistance',
        'property_details' => ['cleaning_mode' => 'deep'],
    ]);
    $defaultRegular = new CleaningBooking([
        'property_type' => 'house',
        'property_details' => [],
    ]);

    expect($regular->dashboardKindLabel())->toBe('تنظيف عادي')
        ->and($regular->dashboardKindColor())->toBe('info')
        ->and($deep->dashboardKindLabel())->toBe('تنظيف عميق')
        ->and($deep->dashboardKindColor())->toBe('purple')
        ->and($event->dashboardKindLabel())->toBe('مساعدة مناسبة')
        ->and($event->dashboardKindColor())->toBe('warning')
        ->and($defaultRegular->dashboardKindLabel())->toBe('تنظيف عادي');
});
