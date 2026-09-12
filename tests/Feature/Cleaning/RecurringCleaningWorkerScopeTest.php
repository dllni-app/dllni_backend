<?php

declare(strict_types=1);

use App\Jobs\NotifyEligibleWorkersNewOrderJob;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\NewOrderRequestNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningAssignmentMode;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSessionWorkerEligibilityService;

use function Pest\Laravel\getJson;

/** @return array{0:User,1:Worker} */
function makeRecurringScopeEligibleWorker(string $email): array
{
    CleaningDepositSetting::query()->firstOrCreate([], [
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 100000,
        'restriction_threshold_percent' => 100,
        'allowance_warning_threshold_percent' => 10,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
    ]);

    $user = User::factory()->create(['email' => $email, 'is_active' => true]);
    $workingHours = [];
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $workingHours[$day] = [
            'available' => true,
            'data' => [['00:00' => '23:59']],
        ];
    }

    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 100,
        'preferred_work_type' => 'both',
        'home_address' => 'Scope test worker home',
        'home_latitude' => 36.2000,
        'home_longitude' => 37.1500,
        'default_working_hours' => $workingHours,
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 100000,
        'debt_balance' => 0,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
        'is_active' => true,
    ]);

    return [$user, $worker];
}

/** @param array<int,int> $specificWorkerIds */
function makeRecurringSpecificScopeBooking(array $specificWorkerIds): array
{
    $customer = User::factory()->create(['is_active' => true]);
    $scheduledAt = now(config('app.timezone'))->addDays(2)->setTime(10, 0);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'assignment_mode' => CleaningAssignmentMode::OpenCount->value,
        'worker_scope' => CleaningBooking::WORKER_SCOPE_SPECIFIC,
        'specific_worker_ids' => $specificWorkerIds,
        'number_of_workers' => count($specificWorkerIds),
        'property_type' => 'apartment',
        'neighborhood_id' => null,
        'neighborhood_name' => null,
        'address_latitude' => 36.2100,
        'address_longitude' => 37.1600,
        'status' => CleaningBookingStatus::Pending->value,
        'gender_preference' => 'any',
        'scheduled_date' => $scheduledAt->toDateString(),
        'scheduled_time' => $scheduledAt->format('H:i'),
        'estimated_hours' => 2,
        'total_hours' => 4,
        'base_price' => 2000,
        'addons_total' => 0,
        'admin_margin_amount' => 200,
        'total_price' => 2200,
    ]);

    $session = CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $scheduledAt->toDateString(),
        'scheduled_time' => $scheduledAt->format('H:i'),
        'duration_hours' => 2,
        'required_workers' => count($specificWorkerIds),
        'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
        'status' => CleaningBookingSessionStatus::Scheduled,
        'base_price' => 2000,
        'addons_total' => 0,
        'materials_total' => 0,
        'special_services_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 200,
        'extension_fee_total' => 0,
        'cancellation_fee' => 0,
        'total_price' => 2200,
        'is_pricing_final' => false,
    ]);

    return [$booking, $session];
}

it('blocks an outsider before recurring session acceptance eligibility checks', function (): void {
    [, $selectedWorker] = makeRecurringScopeEligibleWorker('scope-selected-eligibility@example.com');
    [, $outsider] = makeRecurringScopeEligibleWorker('scope-outsider-eligibility@example.com');
    [$booking, $session] = makeRecurringSpecificScopeBooking([(int) $selectedWorker->id]);

    expect(app(Modules\Cleaning\Services\DepositService::class)->isWorkerEligibleForNewRequests($selectedWorker->fresh(['deposit'])))->toBeTrue();

    $result = app(CleaningBookingSessionWorkerEligibilityService::class)
        ->check($booking->fresh(), $session->fresh(), $outsider);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reasonCode'])->toBe('worker_not_in_scope');
});

it('shows an explicit multi-specific pending booking only to selected workers', function (): void {
    [$selectedUser, $selectedWorker] = makeRecurringScopeEligibleWorker('scope-selected-filter@example.com');
    [$otherSelectedUser, $otherSelectedWorker] = makeRecurringScopeEligibleWorker('scope-selected-filter-2@example.com');
    [$outsiderUser] = makeRecurringScopeEligibleWorker('scope-outsider-filter@example.com');
    [$booking] = makeRecurringSpecificScopeBooking([
        (int) $selectedWorker->id,
        (int) $otherSelectedWorker->id,
    ]);

    Sanctum::actingAs($selectedUser);
    expect(CleaningBooking::query()->forCurrentWorker(true)->whereKey($booking->id)->exists())->toBeTrue();
    $selectedSolvency = app(Modules\Cleaning\Services\WorkerOrderSolvencyService::class)
        ->solvencyPayloadForBooking($selectedWorker->fresh(['user', 'deposit']), $booking->fresh());
    if (! (bool) ($selectedSolvency['canReceiveOrder'] ?? false)) {
        throw new RuntimeException('selected scope solvency: '.json_encode($selectedSolvency));
    }
    $selectedIds = collect(getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending')
        ->assertOk()
        ->json('data'))->pluck('id');
    expect($selectedIds)->toContain($booking->id);

    Sanctum::actingAs($otherSelectedUser);
    $otherSelectedIds = collect(getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending')
        ->assertOk()
        ->json('data'))->pluck('id');
    expect($otherSelectedIds)->toContain($booking->id);

    Sanctum::actingAs($outsiderUser);
    $outsiderIds = collect(getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending')
        ->assertOk()
        ->json('data'))->pluck('id');
    expect($outsiderIds)->not->toContain($booking->id);
});

it('dispatches explicit recurring specific scope only to selected workers', function (): void {
    Notification::fake();

    [$firstUser, $firstWorker] = makeRecurringScopeEligibleWorker('scope-selected-notify-1@example.com');
    [$secondUser, $secondWorker] = makeRecurringScopeEligibleWorker('scope-selected-notify-2@example.com');
    [$outsiderUser] = makeRecurringScopeEligibleWorker('scope-outsider-notify@example.com');
    [$booking] = makeRecurringSpecificScopeBooking([
        (int) $firstWorker->id,
        (int) $secondWorker->id,
    ]);

    $bookingStartsAt = Carbon\Carbon::parse(
        $booking->scheduled_date->format('Y-m-d').' '.mb_trim((string) $booking->scheduled_time),
        config('app.timezone'),
    );
    expect(app(Modules\Cleaning\Services\DepositService::class)->isWorkerEligibleForDispatch($firstWorker->fresh(['user', 'deposit'])))->toBeTrue()
        ->and(app(Modules\Cleaning\Services\DepositService::class)->isWorkerEligibleForDispatch($secondWorker->fresh(['user', 'deposit'])))->toBeTrue()
        ->and($firstWorker->fresh()->isAvailableAt($bookingStartsAt))->toBeTrue()
        ->and($secondWorker->fresh()->isAvailableAt($bookingStartsAt))->toBeTrue();
    foreach ([$firstWorker, $secondWorker] as $candidate) {
        $candidateSolvency = app(Modules\Cleaning\Services\WorkerOrderSolvencyService::class)
            ->solvencyPayloadForBooking($candidate->fresh(['user', 'deposit']), $booking->fresh());
        if (! (bool) ($candidateSolvency['canReceiveOrder'] ?? false)) {
            throw new RuntimeException('dispatch scope solvency: '.json_encode($candidateSolvency));
        }
    }

    (new NotifyEligibleWorkersNewOrderJob((int) $booking->id))->handle();

    Notification::assertSentTo($firstUser, NewOrderRequestNotification::class);
    Notification::assertSentTo($secondUser, NewOrderRequestNotification::class);
    Notification::assertNotSentTo($outsiderUser, NewOrderRequestNotification::class);
});
