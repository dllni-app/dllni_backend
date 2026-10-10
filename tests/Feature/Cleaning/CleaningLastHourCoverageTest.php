<?php

declare(strict_types=1);

use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningLastHourCoverageService;

beforeEach(function (): void {
    Notification::fake();
    $this->startAt = now()->addDays(2)->setTime(12, 0, 0);
    $this->booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending,
        'scheduled_date' => $this->startAt->toDateString(),
        'scheduled_time' => '12:00',
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
    ]);
});

it('retains an unmatched order until the final hour and then cancels it once', function (): void {
    $service = app(CleaningLastHourCoverageService::class);
    $early = $service->processDue(CarbonImmutable::instance($this->startAt->copy()->subMinutes(61)));
    expect($early['cancelled'])->toBe(0);
    expect($this->booking->fresh()->status)->toBe(CleaningBookingStatus::Pending);

    $due = $service->processDue(CarbonImmutable::instance($this->startAt->copy()->subHour()));
    expect($due['cancelled'])->toBe(1);
    expect($this->booking->fresh()->status)->toBe(CleaningBookingStatus::Cancelled);
    expect($this->booking->fresh()->cancelled_by_role)->toBe('system');
    expect($service->processDue(CarbonImmutable::instance($this->startAt->copy()->subMinutes(59)))['cancelled'])->toBe(0);
});

it('does not cancel a partial team and prompts the customer just once', function (): void {
    $worker = Worker::factory()->create();
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $this->booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        'accepted_at' => now(),
    ]);
    $service = app(CleaningLastHourCoverageService::class);
    $clock = CarbonImmutable::instance($this->startAt->copy()->subHour());
    $due = $service->processDue($clock);
    expect($due)->toBe(['cancelled' => 0, 'prompted' => 1]);
    expect($this->booking->fresh()->status)->toBe(CleaningBookingStatus::Pending);
    expect($service->decision($this->booking->fresh(), $clock)['required'])->toBeTrue();
    expect($service->processDue($clock)['prompted'])->toBe(0);
});

it('assigns all rooms to a single accepted worker only after customer chooses it', function (): void {
    $worker = Worker::factory()->create();
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $this->booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        'accepted_at' => now(),
    ]);
    foreach ([1, 2] as $slot) {
        CleaningBookingRoom::query()->create([
            'cleaning_booking_id' => $this->booking->id,
            'room_key' => 'bedroom-'.$slot,
            'room_type' => 'bedroom',
            'room_size' => 'small',
            'display_label' => 'غرفة نوم '.$slot,
            'weight' => 1,
            'planned_worker_slot' => $slot,
            'assigned_worker_id' => $slot === 1 ? $worker->id : null,
        ]);
    }
    $this->travelTo($this->startAt->copy()->subMinutes(50));
    $service = app(CleaningLastHourCoverageService::class);
    expect($service->decision($this->booking->fresh())['required'])->toBeTrue();
    $updated = $service->decide($this->booking, 'all_tasks', $worker->id);
    expect($updated->number_of_workers)->toBe(1);
    expect($updated->status)->toBe(CleaningBookingStatus::WorkerAssigned);
    expect($updated->last_hour_team_decision)->toBe('all_tasks');
    expect(CleaningBookingRoom::query()->where('cleaning_booking_id', $this->booking->id)
        ->where('assigned_worker_id', $worker->id)->count())->toBe(2);
    expect($service->decision($updated)['required'])->toBeFalse();
    Notification::assertSentTo(
        $worker->user,
        \App\Notifications\Cleaning\BookingLifecycleNotification::class,
    );
});

it('keeps original room assignments if customer chooses assigned-only', function (): void {
    $worker = Worker::factory()->create();
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $this->booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        'accepted_at' => now(),
    ]);
    $room = CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $this->booking->id,
        'room_key' => 'bedroom-1',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'غرفة نوم',
        'weight' => 1,
        'planned_worker_slot' => 2,
        'assigned_worker_id' => null,
    ]);
    $this->travelTo($this->startAt->copy()->subMinutes(50));
    $updated = app(CleaningLastHourCoverageService::class)
        ->decide($this->booking, 'assigned_only', null);
    expect($updated->last_hour_team_decision)->toBe('assigned_only');
    expect($updated->number_of_workers)->toBe(2);
    expect($room->fresh()->assigned_worker_id)->toBeNull();
    expect($updated->status)->toBe(CleaningBookingStatus::Pending);
});

it('exposes the customer decision endpoint only to the booking owner', function (): void {
    $worker = Worker::factory()->create();
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $this->booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        'accepted_at' => now(),
    ]);
    $this->travelTo($this->startAt->copy()->subMinutes(50));
    \Laravel\Sanctum\Sanctum::actingAs(\App\Models\User::factory()->create());
    \Pest\Laravel\postJson(
        '/api/v1/user/cleaning/orders/'.$this->booking->id.'/last-hour-team-decision',
        ['choice' => 'assigned_only'],
    )->assertNotFound();
    \Laravel\Sanctum\Sanctum::actingAs($this->booking->customer);
    \Pest\Laravel\postJson(
        '/api/v1/user/cleaning/orders/'.$this->booking->id.'/last-hour-team-decision',
        ['choice' => 'assigned_only'],
    )->assertOk()->assertJsonPath('data.lastHourTeamDecision.decision', 'assigned_only');
});

it('rejects changing task coverage outside the final hour', function (): void {
    $worker = Worker::factory()->create();
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $this->booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        'accepted_at' => now(),
    ]);
    app(CleaningLastHourCoverageService::class)->decide(
        $this->booking, 'all_tasks', $worker->id,
    );
})->throws(\Illuminate\Validation\ValidationException::class);
