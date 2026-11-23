<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningV3DispatchNoticeDeliveryService;
use Modules\Cleaning\Services\CleaningV3VisitDispatchService;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/** @return array{0:CleaningBookingSession,1:User,2:CarbonImmutable} */
function v3NoticeFixture(): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => 'pending',
        'number_of_workers' => 2,
    ]);
    $booking->forceFill(['contract_version' => 'v3'])->save();

    $start = CarbonImmutable::parse('2026-11-23 09:00:00', 'UTC');
    $appointment = $start->addDay()
        ->setTimezone(config('cleaning_booking_v2.timezone', 'Asia/Damascus'));
    $visit = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $appointment->toDateString(),
        'scheduled_time' => $appointment->format('H:i'),
        'duration_hours' => 2,
        'required_workers' => 2,
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);

    app(CleaningV3VisitDispatchService::class)->openSearch($visit, $start);
    expect(app(CleaningV3VisitDispatchService::class)->escalateDue($start->addHours(6)))->toBe(1);

    return [$visit, $customer, $start];
}

function v3ShortageInboxCount(User $user): int
{
    return $user->notifications()
        ->where('type', Modules\Cleaning\Notifications\CleaningV3VisitShortageNotification::class)
        ->count();
}

test('six-hour V3 notices are persisted once in customer and admin inboxes', function (): void {
    [$visit, $customer] = v3NoticeFixture();
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));

    $service = app(CleaningV3DispatchNoticeDeliveryService::class);
    expect($service->deliverPending())->toBe([
        'delivered' => 2, 'cancelled' => 0, 'failed' => 0,
    ]);

    expect(v3ShortageInboxCount($customer))->toBe(1)
        ->and(v3ShortageInboxCount($admin))->toBe(1)
        ->and(DB::table('cleaning_v3_dispatch_notice_intents')->whereNotNull('delivered_at')->count())->toBe(2)
        ->and($service->deliverPending())->toBe([
            'delivered' => 0, 'cancelled' => 0, 'failed' => 0,
        ])
        ->and(v3ShortageInboxCount($customer))->toBe(1)
        ->and(v3ShortageInboxCount($admin))->toBe(1);

    $customerMessage = $customer->notifications()
        ->where('type', Modules\Cleaning\Notifications\CleaningV3VisitShortageNotification::class)
        ->first();
    expect($customerMessage->data['bookingId'])->toBe((int) $visit->cleaning_booking_id)
        ->and($customerMessage->data['sessionId'])->toBe((int) $visit->id)
        ->and($customerMessage->data['retryOrCancel'])->toBeFalse()
        ->and($customerMessage->data['remainingWorkers'])->toBe(2)
        ->and($customerMessage->type)->toBe(Modules\Cleaning\Notifications\CleaningV3VisitShortageNotification::class);
});

test('resolved shortages cancel queued inbox intents before any stale notification is sent', function (): void {
    [$visit, $customer, $start] = v3NoticeFixture();
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));

    for ($i = 0; $i < 2; $i++) {
        $user = User::factory()->create(['is_active' => true]);
        $worker = Worker::factory()->create(['user_id' => $user->id]);
        $visit->workerAssignments()->create([
            'worker_id' => $worker->id,
            'status' => 'accepted',
            'accepted_at' => $start->addHours(7),
        ]);
    }
    app(CleaningV3VisitDispatchService::class)
        ->reconcileCoverage($visit, $start->addHours(7));

    $stats = app(CleaningV3DispatchNoticeDeliveryService::class)->deliverPending();
    expect($stats)->toBe(['delivered' => 0, 'cancelled' => 2, 'failed' => 0])
        ->and(v3ShortageInboxCount($customer))->toBe(0)
        ->and(v3ShortageInboxCount($admin))->toBe(0)
        ->and(DB::table('cleaning_v3_dispatch_notice_intents')->whereNotNull('cancelled_at')->count())->toBe(2);
});

test('a missing admin inbox never pretends delivery and can retry without re-notifying the customer', function (): void {
    [, $customer] = v3NoticeFixture();

    $service = app(CleaningV3DispatchNoticeDeliveryService::class);
    $first = $service->deliverPending();
    expect($first['delivered'])->toBe(1)
        ->and($first['failed'])->toBe(1)
        ->and(v3ShortageInboxCount($customer))->toBe(1);

    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));

    expect($service->deliverPending())->toBe([
        'delivered' => 1, 'cancelled' => 0, 'failed' => 0,
    ])
        ->and(v3ShortageInboxCount($customer))->toBe(1)
        ->and(v3ShortageInboxCount($admin))->toBe(1)
        ->and(DB::table('cleaning_v3_dispatch_notice_intents')
            ->where('recipient_kind', 'admin')->value('attempts'))->toBe(2);
});

test('the scheduled V3 inbox delivery command is registered without mutating V2 bookings', function (): void {
    [$visit] = v3NoticeFixture();
    $this->artisan('cleaning:v3-deliver-dispatch-notices')->assertExitCode(0);

    expect($visit->booking->fresh()->status->value)->toBe('pending')
        ->and(DB::table('cleaning_booking_quotes')->count())->toBe(0)
        ->and(DB::table('cleaning_visit_funding_reservations')->count())->toBe(0);
});
