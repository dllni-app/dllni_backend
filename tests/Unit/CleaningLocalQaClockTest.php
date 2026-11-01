<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningLocalQaClock;

it('fails closed when the feature is disabled', function (): void {
    config()->set('cleaning_qa.enabled', false);
    config()->set('cleaning_qa.time_travel', true);
    config()->set('cleaning_qa.allow_early_actions', true);
    $clock = app(CleaningLocalQaClock::class);
    $session = new CleaningBookingSession();

    expect($clock->permitted(new User(['email' => 'admin@admin.com']), $session))->toBeFalse();
});

it('rejects non-local environment before touching a database', function (): void {
    config()->set('cleaning_qa.enabled', true);
    config()->set('cleaning_qa.time_travel', true);
    config()->set('cleaning_qa.allow_early_actions', true);
    app()->detectEnvironment(fn () => 'production');

    expect(app(CleaningLocalQaClock::class)->permitted(new User(), new CleaningBookingSession()))->toBeFalse();
});

it('rejects the wrong database driver before checking actor identity', function (): void {
    config()->set('cleaning_qa.enabled', true);
    config()->set('cleaning_qa.time_travel', true);
    config()->set('cleaning_qa.allow_early_actions', true);
    config()->set('database.default', 'sqlite');

    expect(app(CleaningLocalQaClock::class)->permitted(new User(), new CleaningBookingSession()))->toBeFalse();
});

it('rejects missing actor and unpersisted session even with QA flags on', function (): void {
    config()->set('cleaning_qa.enabled', true);
    config()->set('cleaning_qa.time_travel', true);
    config()->set('cleaning_qa.allow_early_actions', true);
    config()->set('database.default', 'sqlite');

    $session = new CleaningBookingSession();
    $clock = app(CleaningLocalQaClock::class);
    expect($clock->permitted(null, $session))->toBeFalse()
        ->and($clock->permitted(new User(['email' => 'not-qa@example.test']), $session))->toBeFalse();
});

it('allows only the scoped active QA actor on a verified local MySQL booking', function (): void {
    app()->detectEnvironment(fn () => 'local');
    config()->set('cleaning_qa.enabled', true);
    config()->set('cleaning_qa.time_travel', true);
    config()->set('cleaning_qa.allow_early_actions', true);
    config()->set('database.default', 'mysql');

    $actor = new User();
    $actor->exists = true;
    $actor->forceFill(['id' => 70001, 'email' => 'cleaning.worker@dllni.sy', 'is_active' => true]);
    $worker = new Worker(['is_active' => true, 'is_suspended' => false, 'is_verified' => true, 'security_deposit_status' => 'active']);
    $worker->forceFill(['id' => 19]);
    $actor->setRelation('worker', $worker);

    $booking = new CleaningBooking(['booking_number' => 'CLN-USER-T6NZQUZ3']);
    $session = new CleaningBookingSession(['scheduled_date' => '2026-11-09', 'scheduled_time' => '09:00:00']);
    $session->forceFill(['id' => 80001, 'cleaning_booking_id' => 90001, 'status' => 'scheduled']);
    $session->exists = true;
    $session->setRelation('booking', $booking);
    $session->setRelation('workerAssignments', collect([new Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment(['worker_id' => 19, 'status' => 'accepted'])]));

    $connection = Mockery::mock();
    $connection->shouldReceive('getConfig')->with('host')->andReturn('127.0.0.1');
    $connection->shouldReceive('getDatabaseName')->andReturn('alnadha_worker_test');
    $connection->shouldReceive('selectOne')->andReturn((object) ['server_host' => gethostname(), 'database_name' => 'alnadha_worker_test']);
    DB::shouldReceive('connection')->with('mysql')->andReturn($connection);

    $clock = app(CleaningLocalQaClock::class);
    expect($clock->permitted($actor, $session))->toBeTrue();
    Log::shouldReceive('info')->once()->with('cleaning.qa.temporal_override', Mockery::on(
        fn (array $context): bool => $context['reason'] === 'worker_start_travel'
            && $context['session_id'] === $session->id
            && isset($context['actual_at'], $context['effective_at']),
    ));
    expect($clock->forScheduling($session, $actor, 'worker_start_travel')->toDateString())->toBe('2026-11-09');

    $worker->is_active = false;
    expect($clock->permitted($actor, $session))->toBeFalse();
    $worker->is_active = true;
    $session->setRelation('workerAssignments', collect());
    expect($clock->permitted($actor, $session))->toBeFalse();
    $session->setRelation('workerAssignments', collect([new Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment(['worker_id' => 19, 'status' => 'accepted'])]));

    $booking->booking_number = 'REAL-CUSTOMER-BOOKING';
    expect($clock->permitted($actor, $session))->toBeFalse();
    config()->set('database.default', 'sqlite');
});
