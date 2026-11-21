<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Cleaning\Services\CleaningSearchEscalationDedupeKey;

test('one booking and one search attempt always have the same escalation key', function (): void {
    $service = new CleaningSearchEscalationDedupeKey;
    $clock = CarbonImmutable::parse('2026-11-21T10:00:00+03:00');
    expect($service->make(101, $clock))->toBe($service->make(101, $clock))
        ->and($service->make(101, $clock))->toMatch('/^[a-f0-9]{64}$/D');
});

test('retry creates its own escalation dedupe window without changing prior one', function (): void {
    $service = new CleaningSearchEscalationDedupeKey;
    $initial = CarbonImmutable::parse('2026-11-21T10:00:00+03:00');
    $retry = $initial->addHours(7);

    expect($service->make(101, $initial))->not->toBe($service->make(101, $retry))
        ->and($service->make(101, $initial))->not->toBe($service->make(102, $initial));
});

test('a fake or unsaved booking cannot obtain a valid escalation key', function (): void {
    expect(fn () => (new CleaningSearchEscalationDedupeKey)->make(0, CarbonImmutable::now()))
        ->toThrow(InvalidArgumentException::class);
});
