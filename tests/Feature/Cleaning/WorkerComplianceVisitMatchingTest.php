<?php

declare(strict_types=1);

use App\Models\Worker;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\WorkerComplianceVisitMatcher;

beforeEach(function (): void {
    config()->set('cleaning.worker_compliance.enabled', true);
    config()->set('cleaning.worker_compliance.matching_enabled', true);
    config()->set('cleaning.worker_compliance.enabled_platforms', ['android']);
    config()->set('cleaning.worker_compliance.maximum_accuracy_m', 50);
    config()->set('cleaning.worker_compliance.proximity_radius_m', 100);
    config()->set('cleaning.worker_compliance.minimum_sample_count', 4);
    config()->set('cleaning.worker_compliance.minimum_dwell_seconds', 600);
    config()->set('cleaning.worker_compliance.maximum_sample_gap_seconds', 240);
    config()->set('cleaning.worker_compliance.maximum_dwell_path_m', 300);
    config()->set('cleaning.worker_compliance.review_cooldown_hours', 24);
});

function createComplianceEligibleSite(Worker $worker, CleaningBooking $booking, string $siteKey = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): void
{
    DB::table('worker_compliance_site_eligibilities')->insert([
        'worker_id' => $worker->id,
        'cleaning_booking_id' => $booking->id,
        'source_type' => 'booking_assignment',
        'source_assignment_id' => random_int(1, 2_000_000_000),
        'site_key' => $siteKey,
        'latitude_encrypted' => Crypt::encryptString((string) $booking->address_latitude),
        'longitude_encrypted' => Crypt::encryptString((string) $booking->address_longitude),
        'site_verified_at' => now()->subDays(2),
        'served_at' => now()->subDays(1),
        'eligible_until' => now()->addDays(90),
        'created_at' => now()->subDay(),
        'updated_at' => now(),
    ]);
}

function createComplianceSampleBatch(
    Worker $worker,
    string $epoch,
    float $latitude,
    float $longitude,
    array $minuteOffsets = [15, 11, 7, 3],
): void
{
    foreach ($minuteOffsets as $offset) {
        DB::table('worker_compliance_samples')->insert([
            'worker_id' => $worker->id,
            'epoch' => $epoch,
            'client_id' => (string) Str::uuid(),
            'captured_at' => now()->subMinutes($offset),
            'received_at' => now(),
            'latitude_encrypted' => Crypt::encryptString((string) $latitude),
            'longitude_encrypted' => Crypt::encryptString((string) $longitude),
            'accuracy_m' => 10,
            'quality' => 'usable',
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

it('captures an encrypted site only for an accepted assignment and enables it only after verified service', function (): void {
    $worker = Worker::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'address_latitude' => 33.5138,
        'address_longitude' => 36.2765,
        'number_of_workers' => 1,
    ]);
    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now()->subHour(),
    ]);

    $snapshot = DB::table('worker_compliance_site_eligibilities')
        ->where('source_type', 'booking_assignment')
        ->where('source_assignment_id', $assignment->id)
        ->first();
    expect($snapshot)->not->toBeNull()
        ->and($snapshot->served_at)->toBeNull()
        ->and($snapshot->eligible_until)->not->toBeNull()
        ->and((float) Crypt::decryptString($snapshot->latitude_encrypted))->toBe(33.5138);

    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::Completed->value,
        'started_travel_at' => now()->subHour(),
        'arrived_at' => now()->subMinutes(50),
        'work_finished_at' => now()->subMinutes(10),
    ])->save();

    $eligible = DB::table('worker_compliance_site_eligibilities')->where('id', $snapshot->id)->first();
    expect($eligible->served_at)->not->toBeNull()
        ->and($eligible->eligible_until)->toBeGreaterThan(now()->toDateTimeString());
});

it('does not mark a multi-worker site eligible without verified attendance evidence', function (): void {
    $worker = Worker::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'address_latitude' => 33.5138,
        'address_longitude' => 36.2765,
        'number_of_workers' => 2,
    ]);
    $assignment = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'accepted_at' => now()->subHour(),
    ]);

    $assignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::Completed->value,
        'started_travel_at' => now()->subHour(),
        'arrived_at' => now()->subMinutes(50),
        'work_finished_at' => now()->subMinutes(10),
    ])->save();

    $snapshot = DB::table('worker_compliance_site_eligibilities')
        ->where('source_type', 'booking_assignment')
        ->where('source_assignment_id', $assignment->id)
        ->first();
    expect($snapshot->served_at)->toBeNull()
        ->and($snapshot->eligible_until)->not->toBeNull();
});

it('does not create an actionable case when a sample run qualifies and no official job overlaps', function (): void {
    $worker = Worker::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Completed->value,
        'address_latitude' => 33.5138,
        'address_longitude' => 36.2765,
        'scheduled_date' => now()->subDays(2)->toDateString(),
        'scheduled_time' => '10:00:00',
    ]);
    $epoch = (string) Str::uuid();
    DB::table('worker_compliance_states')->insert([
        'worker_id' => $worker->id,
        'is_enrolled' => true,
        'platform' => 'android',
        'build' => '1',
        'epoch' => $epoch,
        'lease_expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    createComplianceEligibleSite($worker, $booking);
    createComplianceSampleBatch($worker, $epoch, 33.5138, 36.2765);

    expect(app(WorkerComplianceVisitMatcher::class)->evaluateWorker((int) $worker->id))->toBe(1);

    $case = DB::table('worker_compliance_review_cases')->where('worker_id', $worker->id)->first();
    expect($case)->not->toBeNull()
        ->and($case->status)->toBe('new')
        ->and($case->reason_code)->toBe('potential_visit_needs_review')
        ->and($case->observation_started_at)->not->toBeNull()
        ->and($case->public_reference)->not->toBeNull()
        ->and($case->expires_at)->toBeNull();

    expect(DB::table('worker_compliance_review_events')->where('case_reference', $case->public_reference)->count())->toBe(1);
    expect(app(WorkerComplianceVisitMatcher::class)->evaluateWorker((int) $worker->id))->toBe(0)
        ->and(DB::table('worker_compliance_review_cases')->where('worker_id', $worker->id)->count())->toBe(1);
});

it('suppresses proximity when an official active same-site booking overlaps the observation', function (): void {
    $worker = Worker::factory()->create();
    $historicalBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Completed->value,
        'address_latitude' => 33.5138,
        'address_longitude' => 36.2765,
        'scheduled_date' => now()->subDays(2)->toDateString(),
        'scheduled_time' => '10:00:00',
    ]);
    $activeBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Cancelled->value,
        'number_of_workers' => 1,
        'address_latitude' => 33.5138,
        'address_longitude' => 36.2765,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->subMinutes(20)->format('H:i:s'),
        'cancelled_at' => now()->subMinute(),
    ]);
    $epoch = (string) Str::uuid();
    DB::table('worker_compliance_states')->insert([
        'worker_id' => $worker->id,
        'is_enrolled' => true,
        'platform' => 'android',
        'build' => '1',
        'epoch' => $epoch,
        'lease_expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    createComplianceEligibleSite($worker, $historicalBooking);
    createComplianceSampleBatch($worker, $epoch, 33.5138, 36.2765);
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $activeBooking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled->value,
        'accepted_at' => now()->subHour(),
        'started_travel_at' => now()->subMinutes(20),
        'arrived_at' => now()->subMinutes(15),
        'work_started_at' => now()->subMinutes(12),
    ]);

    expect(app(WorkerComplianceVisitMatcher::class)->evaluateWorker((int) $worker->id))->toBe(0)
        ->and(DB::table('worker_compliance_review_cases')->count())->toBe(0);
});

it('fails closed when an overlapping official assignment has no verifiable site coordinates', function (): void {
    $worker = Worker::factory()->create();
    $historicalBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Completed->value,
        'address_latitude' => 33.5138,
        'address_longitude' => 36.2765,
        'scheduled_date' => now()->subDays(2)->toDateString(),
        'scheduled_time' => '10:00:00',
    ]);
    $activeBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress->value,
        'number_of_workers' => 1,
        'address_latitude' => null,
        'address_longitude' => null,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->subMinutes(20)->format('H:i:s'),
    ]);
    $epoch = (string) Str::uuid();
    DB::table('worker_compliance_states')->insert([
        'worker_id' => $worker->id,
        'is_enrolled' => true,
        'platform' => 'android',
        'build' => '1',
        'epoch' => $epoch,
        'lease_expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    createComplianceEligibleSite($worker, $historicalBooking);
    createComplianceSampleBatch($worker, $epoch, 33.5138, 36.2765);
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $activeBooking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
        'accepted_at' => now()->subHour(),
        'started_travel_at' => now()->subMinutes(20),
        'arrived_at' => now()->subMinutes(15),
        'work_started_at' => now()->subMinutes(12),
    ]);

    expect(app(WorkerComplianceVisitMatcher::class)->evaluateWorker((int) $worker->id))->toBe(0)
        ->and(DB::table('worker_compliance_review_cases')->count())->toBe(0);
});

it('suppresses ambiguous proximity to two distinct eligible sites', function (): void {
    $worker = Worker::factory()->create();
    $firstBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Completed->value,
        'address_latitude' => 33.5138,
        'address_longitude' => 36.2765,
        'scheduled_date' => now()->subDays(2)->toDateString(),
        'scheduled_time' => '10:00:00',
    ]);
    $secondBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Completed->value,
        'address_latitude' => 33.5139,
        'address_longitude' => 36.2765,
        'scheduled_date' => now()->subDays(3)->toDateString(),
        'scheduled_time' => '10:00:00',
    ]);
    $epoch = (string) Str::uuid();
    DB::table('worker_compliance_states')->insert([
        'worker_id' => $worker->id,
        'is_enrolled' => true,
        'platform' => 'android',
        'build' => '1',
        'epoch' => $epoch,
        'lease_expires_at' => now()->addHour(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    createComplianceEligibleSite($worker, $firstBooking, str_repeat('a', 64));
    createComplianceEligibleSite($worker, $secondBooking, str_repeat('b', 64));
    createComplianceSampleBatch($worker, $epoch, 33.5138, 36.2765);

    expect(app(WorkerComplianceVisitMatcher::class)->evaluateWorker((int) $worker->id))->toBe(0)
        ->and(DB::table('worker_compliance_review_cases')->count())->toBe(0);
});
