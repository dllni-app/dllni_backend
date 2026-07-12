<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CleaningOrderAwaitingCustomerCompletion;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

beforeEach(function (): void {
    $this->billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
});

it('returns finished tasks and services for a single-worker booking that has an assignment', function (): void {
    Event::fake([
        CleaningBookingTrackingUpdated::class,
        CleaningOrderAwaitingCustomerCompletion::class,
    ]);

    $customer = User::factory()->create(['email' => 'completion-customer@example.com']);
    $workerUser = User::factory()->create(['email' => 'completion-worker@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::InProgress,
        'work_started_at' => now()->subHour(),
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress,
        'accepted_at' => now()->subHours(2),
        'start_approved_at' => now()->subHour(),
        'work_started_at' => now()->subHour(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);

    $payload = [
        'completionMessage' => 'تم إنجاز جميع المهام المطلوبة.',
        'cleaningServices' => [
            [
                'id' => 11,
                'name' => 'تنظيف عميق',
                'label' => 'تنظيف عميق',
            ],
            [
                'id' => 12,
                'name' => 'تنظيف النوافذ',
                'label' => 'تنظيف النوافذ',
            ],
        ],
        'propertiesRooms' => [
            [
                'id' => 21,
                'name' => 'حمام 1',
                'label' => 'حمام 1: كبيرة',
                'detail' => 'كبيرة',
            ],
        ],
    ];

    Sanctum::actingAs($workerUser);

    $completionResponse = $this->postJson(
        "/api/v1/cleaning-bookings/{$booking->id}/complete",
        $payload,
    );

    $completionResponse
        ->assertOk()
        ->assertJsonPath('data.status', CleaningBookingStatus::AwaitingCustomerCompletion->value)
        ->assertJsonPath('data.completionRequests.0.message', 'تم إنجاز جميع المهام المطلوبة.')
        ->assertJsonPath('data.completionRequests.0.finishedCleaningServices.0.name', 'تنظيف عميق')
        ->assertJsonPath('data.completionRequests.0.finishedCleaningServices.1.name', 'تنظيف النوافذ')
        ->assertJsonPath('data.completionRequests.0.finishedPropertyRooms.0.label', 'حمام 1: كبيرة');

    $booking->refresh();

    expect($booking->worker_finished_cleaning_services)->toHaveCount(2)
        ->and($booking->worker_finished_property_rooms)->toHaveCount(1);

    Sanctum::actingAs($customer);

    $this->getJson("/api/v1/user/cleaning/orders/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.completionRequests.0.finishedCleaningServices.0.name', 'تنظيف عميق')
        ->assertJsonPath('data.completionRequests.0.finishedCleaningServices.1.name', 'تنظيف النوافذ')
        ->assertJsonPath('data.completionRequests.0.finishedPropertyRooms.0.label', 'حمام 1: كبيرة');
});

it('does not reuse booking snapshots for an empty assignment in a multi-worker order', function (): void {
    $firstWorker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    $secondWorker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);

    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => $this->billingPolicy->id,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
        'worker_finished_cleaning_services' => [
            ['name' => 'خدمة العامل الأول', 'label' => 'خدمة العامل الأول'],
        ],
        'worker_finished_property_rooms' => [
            ['name' => 'غرفة العامل الأول', 'label' => 'غرفة العامل الأول'],
        ],
    ]);

    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $secondWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion,
        'accepted_at' => now()->subHour(),
        'work_finished_at' => now(),
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);

    expect($assignment->worker_finished_cleaning_services)->toBe([])
        ->and($assignment->worker_finished_property_rooms)->toBe([])
        ->and($firstWorker->id)->not->toBe($secondWorker->id);
});
