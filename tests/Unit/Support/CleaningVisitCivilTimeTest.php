<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Support\CleaningVisitCivilTime;

it('interprets cleaning visit civil dates in the configured service timezone regardless of Laravel UTC', function (): void {
    config(['app.timezone' => 'UTC', 'cleaning_booking_v2.timezone' => 'Asia/Damascus']);

    $start = CleaningVisitCivilTime::parse('2026-11-03', '14:00');
    expect($start)->toBeInstanceOf(CarbonImmutable::class)
        ->and($start->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-03 11:00:00');

    $session = new CleaningBookingSession([
        'scheduled_date' => '2026-11-03',
        'scheduled_time' => '14:00:00',
        'duration_hours' => 2,
    ]);

    expect($session->startsAt()?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-03 11:00:00')
        ->and($session->endsAt()?->utc()->format('Y-m-d H:i:s'))->toBe('2026-11-03 13:00:00');
});

it('does not change visit instant when application timezone changes', function (): void {
    config(['cleaning_booking_v2.timezone' => 'Asia/Damascus']);

    config(['app.timezone' => 'UTC']);
    $utcApp = CleaningVisitCivilTime::parse('2026-11-03', '09:00');
    config(['app.timezone' => 'Europe/Istanbul']);
    $istanbulApp = CleaningVisitCivilTime::parse('2026-11-03', '09:00');

    expect($utcApp?->getTimestamp())->toBe($istanbulApp?->getTimestamp());
});

it('rejects invalid or missing visit civil dates instead of creating a bogus interval', function (): void {
    expect(CleaningVisitCivilTime::parse('', '09:00'))->toBeNull()
        ->and(CleaningVisitCivilTime::parse('2026-11-03', ''))->toBeNull()
        ->and(CleaningVisitCivilTime::parse('bad-date', 'bad-time'))->toBeNull();
});
