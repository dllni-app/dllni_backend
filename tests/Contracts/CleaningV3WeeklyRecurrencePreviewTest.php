<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3VisitPlanner;
use Modules\Cleaning\Services\CleaningV3WeeklyRecurrencePreviewService;

function v3WeeklyInput(): array
{
    return [
        'seriesKey' => 'weeklyclean-17',
        'catalogVersionKey' => 'catalog-1',
        'catalogRevision' => str_repeat('a', 64),
        'startDate' => '2026-12-14',
        'startTime' => '10:00',
        'weekdays' => [3, 1],
        'intervalWeeks' => 2,
        'occurrenceCount' => 5,
        'durationMinutes' => 120,
        'requiredWorkers' => 2,
    ];
}

function v3WeeklyPreview(array $overrides = [], ?DateTimeImmutable $at = null): array
{
    return (new CleaningV3WeeklyRecurrencePreviewService(new CleaningV3VisitPlanner))
        ->preview(array_replace(v3WeeklyInput(), $overrides),
            $at ?? new DateTimeImmutable('2026-11-23T09:00:00+03:00'));
}

test('biweekly Monday and Wednesday produce deterministic visits with distinct stable occurrence keys', function (): void {
    $plan = v3WeeklyPreview();
    $dates = array_map(static fn (array $row): string => $row['date'], $plan['appointments']);
    $keys = array_map(static fn (array $row): string => $row['visits'][0]['occurrenceKey'], $plan['appointments']);

    expect($dates)->toBe(['2026-12-14', '2026-12-16', '2026-12-28', '2026-12-30', '2027-01-11'])
        ->and($keys)->toBe([
            'weeklyclean-17-0001', 'weeklyclean-17-0002',
            'weeklyclean-17-0003', 'weeklyclean-17-0004', 'weeklyclean-17-0005',
        ])
        ->and($plan['summary']['appointmentCount'])->toBe(5)
        ->and($plan['summary']['visitCount'])->toBe(5)
        ->and($plan['summary']['totalActiveMinutes'])->toBe(600)
        ->and($plan['summary']['peakRequestedWorkers'])->toBe(2)
        ->and($plan['recurrence']['weekdays'])->toBe([1, 3])
        ->and($plan['canQuote'])->toBeFalse()
        ->and($plan['canCreateBooking'])->toBeFalse();
});

test('reordering selected weekdays does not change occurrence IDs or the schedule fingerprint', function (): void {
    $one = v3WeeklyPreview(['weekdays' => [1, 3]]);
    $two = v3WeeklyPreview(['weekdays' => [3, 1]]);

    expect($one['appointments'])->toEqual($two['appointments'])
        ->and($one['draftFingerprint'])->toBe($two['draftFingerprint']);
});

test('recurrence rejects repeated or invalid weekdays rather than doubling visit count', function (): void {
    foreach ([[1, 1], [0], [8], [1, '3'], []] as $days) {
        expect(fn () => v3WeeklyPreview(['weekdays' => $days]))
            ->toThrow(DomainException::class);
    }
});

test('past dates, invalid local dates and cross-midnight visits never create a draft', function (): void {
    expect(fn () => v3WeeklyPreview(['startDate' => '2026-11-22']))
        ->toThrow(DomainException::class, 'V3_RECURRENCE_START_MUST_BE_FUTURE');
    expect(fn () => v3WeeklyPreview(['startDate' => '2026-02-30']))
        ->toThrow(DomainException::class, 'V3_RECURRENCE_START_MUST_BE_FUTURE');
    expect(fn () => v3WeeklyPreview([
        'startTime' => '23:00',
        'durationMinutes' => 120,
    ]))->toThrow(DomainException::class, 'V3_RECURRENCE_CROSS_MIDNIGHT');
});

test('recurrence rejects more than thirty visits or rules outside a one-year horizon', function (): void {
    expect(fn () => v3WeeklyPreview(['occurrenceCount' => 31]))
        ->toThrow(DomainException::class, 'INVALID_V3_RECURRENCE_RULE');

    expect(fn () => v3WeeklyPreview([
        'occurrenceCount' => 10,
        'intervalWeeks' => 12,
        'weekdays' => [1],
    ]))->toThrow(DomainException::class, 'V3_RECURRENCE_HORIZON_EXCEEDED');
});

test('pricing, financial identifiers and unknown fields are never accepted from the customer', function (): void {
    expect(fn () => v3WeeklyPreview(['quotedPrice' => '10.00']))
        ->toThrow(DomainException::class, 'INVALID_V3_RECURRENCE_RULE');
    expect(fn () => v3WeeklyPreview(['durationMinutes' => 481]))
        ->toThrow(DomainException::class, 'INVALID_V3_RECURRENCE_RULE');
    expect(fn () => v3WeeklyPreview(['requiredWorkers' => 0]))
        ->toThrow(DomainException::class, 'INVALID_V3_RECURRENCE_RULE');
});
