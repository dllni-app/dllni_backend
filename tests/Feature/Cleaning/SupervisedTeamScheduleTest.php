<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisedTeamScheduleService;

it('calculates one authoritative team interval for two three and four workers', function (): void {
    $schedule = app(SupervisedTeamScheduleService::class);

    foreach ([2 => ['duration' => 360, 'end' => '15:00'],
              3 => ['duration' => 240, 'end' => '13:00'],
              4 => ['duration' => 180, 'end' => '12:00']] as $workers => $expected) {
        $data = $schedule->estimate(720, $workers, '2026-10-19', '09:00');
        expect($data['baseMinutes'])->toBe(720)
            ->and($data['durationMinutes'])->toBe($expected['duration'])
            ->and(substr($data['scheduledEndAt'], 11, 5))->toBe($expected['end']);
    }
    expect($schedule->estimate(721, 3, '2026-10-19', '09:00')['durationMinutes'])->toBe(241);
});

it('rejects supervised bookings with too few workers', function (): void {
    app(SupervisedTeamScheduleService::class)->estimate(720, 1, '2026-10-19', '09:00');
})->throws(InvalidArgumentException::class);

it('persists one team interval without assigning worker rooms', function (): void {
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'supervised_team',
        'number_of_workers' => 3,
        'scheduled_date' => '2026-10-19',
        'scheduled_time' => '09:00',
    ]);
    $schedule = app(SupervisedTeamScheduleService::class);
    $result = $schedule->estimate(720, 3, '2026-10-19', '09:00');
    $schedule->initialize($booking, $result);
    $booking->refresh();

    expect($booking->isSupervisedTeam())->toBeTrue()
        ->and($booking->supervised_state)->toBe('staffing')
        ->and($booking->supervised_base_minutes)->toBe(720)
        ->and($booking->supervised_duration_minutes)->toBe(240)
        ->and($booking->supervised_original_end_at->format('H:i'))->toBe('13:00')
        ->and($booking->rooms()->count())->toBe(0);
});
