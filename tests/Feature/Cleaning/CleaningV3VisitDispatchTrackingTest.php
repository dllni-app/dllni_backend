<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningV3VisitDispatchService;

uses(RefreshDatabase::class);

/** @return array{0:CleaningBookingSession,1:User,2:CarbonImmutable} */
function v3DispatchTestVisit(int $workers = 3, bool $v3 = true): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'pending',
        'booking_kind' => 'standard',
        'number_of_workers' => $workers,
    ]);
    if ($v3) {
        $booking->forceFill(['contract_version' => 'v3'])->save();
    }

    $start = CarbonImmutable::parse('2026-11-23 09:00:00', 'UTC');
    $date = $start->addDay()
        ->setTimezone(config('cleaning_booking_v2.timezone', 'Asia/Damascus'));
    $visit = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $date->toDateString(),
        'scheduled_time' => $date->format('H:i'),
        'duration_hours' => 2,
        'required_workers' => $workers,
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);

    return [$visit, $customer, $start];
}

function v3DispatchTestAccept(CleaningBookingSession $visit, int $count, CarbonImmutable $at): array
{
    $assignments = [];
    for ($i = 0; $i < $count; $i++) {
        $workerUser = User::factory()->create(['is_active' => true]);
        $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
        $assignments[] = $visit->workerAssignments()->create([
            'worker_id' => $worker->id,
            'status' => 'accepted',
            'accepted_at' => $at,
        ]);
    }

    return $assignments;
}

test('one immutable six-hour deadline belongs to a V3 visit, not worker offers or repeated polling', function (): void {
    [$visit, , $start] = v3DispatchTestVisit();
    $service = app(CleaningV3VisitDispatchService::class);

    $first = $service->openSearch($visit, $start);
    $again = $service->openSearch($visit, $start->addHours(2));

    expect($first['dispatchStatus'])->toBe('searching')
        ->and($first['requiredWorkers'])->toBe(3)
        ->and($first['remainingWorkers'])->toBe(3)
        ->and($first['deadlineAt'])->toBe($again['deadlineAt'])
        ->and($first['firstSearchStartedAt'])->toBe($again['firstSearchStartedAt'])
        ->and(DB::table('cleaning_v3_visit_dispatches')
            ->where('cleaning_booking_session_id', $visit->id)->count())->toBe(1);

    expect($service->escalateDue($start->addMinutes(359)))->toBe(0)
        ->and($service->escalateDue($start->addHours(6)))->toBe(1)
        ->and($service->escalateDue($start->addHours(7)))->toBe(0);

    $after = $service->project($visit, $start->addHours(7));
    expect($after['dispatchStatus'])->toBe('escalated')
        ->and($after['remainingWorkers'])->toBe(3)
        ->and($visit->fresh()->status->value)->toBe('scheduled')
        ->and(DB::table('cleaning_v3_dispatch_notice_intents')->count())->toBe(2)
        ->and(DB::table('cleaning_v3_dispatch_notice_intents')->whereNull('delivered_at')->count())->toBe(2);
});

test('partial acceptance and worker withdrawal do not reset search time or generate duplicate escalation', function (): void {
    [$visit, , $start] = v3DispatchTestVisit();
    $service = app(CleaningV3VisitDispatchService::class);
    $service->openSearch($visit, $start);
    $assignments = v3DispatchTestAccept($visit, 1, $start->addHour());
    $service->reconcileCoverage($visit, $start->addHour());

    $one = $service->project($visit, $start->addHours(2));
    expect($one['acceptedWorkers'])->toBe(1)
        ->and($one['remainingWorkers'])->toBe(2)
        ->and($one['dispatchStatus'])->toBe('searching');

    expect($service->escalateDue($start->addHours(6)))->toBe(1);

    $assignments[0]->forceFill(['status' => 'withdrawn', 'released_at' => $start->addHours(7)])->save();
    $service->reconcileCoverage($visit, $start->addHours(7));
    $after = $service->openSearch($visit, $start->addHours(7));

    expect($after['remainingWorkers'])->toBe(3)
        ->and($after['firstSearchStartedAt'])->toBe($one['firstSearchStartedAt'])
        ->and($after['deadlineAt'])->toBe($one['deadlineAt'])
        ->and($after['escalatedAt'])->not->toBeNull()
        ->and($service->escalateDue($start->addHours(8)))->toBe(0)
        ->and(DB::table('cleaning_v3_dispatch_notice_intents')->count())->toBe(2);
});

test('a fully covered team resolves an incomplete search without a six-hour escalation', function (): void {
    [$visit, , $start] = v3DispatchTestVisit(2);
    $service = app(CleaningV3VisitDispatchService::class);
    $service->openSearch($visit, $start);
    $assignments = v3DispatchTestAccept($visit, 2, $start->addHour());
    $service->reconcileCoverage($visit, $start->addHour());

    $covered = $service->project($visit, $start->addHours(5));
    expect($covered['dispatchStatus'])->toBe('covered')
        ->and($covered['remainingWorkers'])->toBe(0)
        ->and($covered['firstFullyCoveredAt'])->not->toBeNull()
        ->and($covered['resolvedAt'])->not->toBeNull()
        ->and($service->escalateDue($start->addHours(7)))->toBe(0);

    $originalDeadline = $covered['deadlineAt'];
    $originalCoveredAt = $covered['firstFullyCoveredAt'];

    $assignments[0]->forceFill(['status' => 'withdrawn', 'released_at' => $start->addHours(8)])->save();
    $service->reconcileCoverage($visit, $start->addHours(8));

    $reopened = $service->project($visit, $start->addHours(8));
    expect($reopened['acceptedWorkers'])->toBe(1)
        ->and($reopened['remainingWorkers'])->toBe(1)
        ->and($reopened['deadlineAt'])->toBe($originalDeadline)
        ->and($reopened['firstFullyCoveredAt'])->toBe($originalCoveredAt)
        ->and($reopened['resolvedAt'])->toBeNull()
        ->and($service->escalateDue($start->addHours(8)))->toBe(1)
        ->and(DB::table('cleaning_v3_dispatch_notice_intents')->count())->toBe(2);
});

test('due scanner detects coverage without a previous reconciliation and does not send false notices', function (): void {
    [$visit, , $start] = v3DispatchTestVisit(1);
    $service = app(CleaningV3VisitDispatchService::class);
    $service->openSearch($visit, $start);
    v3DispatchTestAccept($visit, 1, $start->addHour());

    expect($service->escalateDue($start->addHours(6)))->toBe(0)
        ->and(DB::table('cleaning_v3_dispatch_notice_intents')->count())->toBe(0);
    $state = $service->project($visit, $start->addHours(6));
    expect($state['dispatchStatus'])->toBe('covered')
        ->and($state['resolvedAt'])->not->toBeNull();
});

test('the V3 progress API is owner-only and does not reveal a sibling customer or V2 visit', function (): void {
    [$visit, $customer, $start] = v3DispatchTestVisit();
    $this->travelTo($start);
    $service = app(CleaningV3VisitDispatchService::class);
    $service->openSearch($visit, $start);
    $url = "/api/v1/cleaning-bookings/{$visit->cleaning_booking_id}/sessions/{$visit->id}/dispatch";
    $outsider = User::factory()->create(['is_active' => true]);

    Sanctum::actingAs($customer);
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.requiredWorkers', 3)
        ->assertJsonPath('data.remainingWorkers', 3)
        ->assertJsonPath('data.dispatchStatus', 'searching')
        ->assertDontSee('customer_id');

    Sanctum::actingAs($outsider);
    $this->getJson($url)->assertNotFound();
    Sanctum::actingAs($customer);
    $this->getJson("/api/v1/cleaning-bookings/{$visit->cleaning_booking_id}/sessions/9999999/dispatch")
        ->assertNotFound();

    [$legacy, $legacyOwner] = v3DispatchTestVisit(1, false);
    Sanctum::actingAs($legacyOwner);
    $this->getJson("/api/v1/cleaning-bookings/{$legacy->cleaning_booking_id}/sessions/{$legacy->id}/dispatch")
        ->assertNotFound();
});

test('a V3 visit never opens new worker search after its scheduled start or closure', function (): void {
    [$visit, , $start] = v3DispatchTestVisit();
    $service = app(CleaningV3VisitDispatchService::class);

    expect(fn () => $service->openSearch($visit, $start->addDays(2)))
        ->toThrow(DomainException::class, 'V3_DISPATCH_REQUIRES_FUTURE_VISIT');
    $visit->forceFill(['status' => 'cancelled'])->save();
    expect(fn () => $service->openSearch($visit->fresh(), $start))
        ->toThrow(DomainException::class, 'V3_DISPATCH_REQUIRES_FUTURE_VISIT');
    expect(DB::table('cleaning_v3_visit_dispatches')->count())->toBe(0);
});
