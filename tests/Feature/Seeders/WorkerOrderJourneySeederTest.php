<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Database\Seeders\WorkerOrderJourneySeeder;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

it('seeds all four worker booking kinds and lifecycle states idempotently', function (): void {
    Worker::factory()->create([
        'user_id' => User::factory()->create(['email' => 'worker1@dllni.sy'])->id,
    ]);
    User::factory()->create(['email' => 'cleaning.customer@dllni.sy']);
    CleaningBillingPolicy::query()->create([
        'name' => 'Worker journey test',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
    DB::table('cleaning_special_services')->insert([
        'name' => 'غسيل الكنبايات بالبخار',
        'slug' => 'sofa-steam',
        'pricing_unit' => 'seat',
        'base_unit_price' => 35,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed(WorkerOrderJourneySeeder::class);
    $this->seed(WorkerOrderJourneySeeder::class);

    $bookings = CleaningBooking::query()
        ->where('booking_number', 'like', 'CLN-W1-JOURNEY-%')
        ->get();
    expect($bookings)->toHaveCount(32);

    foreach (['ROOM', 'SPECIAL', 'EVENT', 'HOURLY'] as $type) {
        $typed = $bookings->filter(fn (CleaningBooking $booking) => str_contains($booking->booking_number, '-'.$type.'-'));
        expect($typed)->toHaveCount(8);
        foreach ([
            CleaningBookingStatus::Pending,
            CleaningBookingStatus::WorkerAssigned,
            CleaningBookingStatus::AwaitingStartVerification,
            CleaningBookingStatus::InProgress,
            CleaningBookingStatus::TimeExtensionRequested,
            CleaningBookingStatus::AwaitingCustomerCompletion,
            CleaningBookingStatus::Completed,
            CleaningBookingStatus::Cancelled,
        ] as $status) {
            expect($typed->contains(fn (CleaningBooking $booking) => $booking->status === $status))->toBeTrue();
        }
    }

    expect(DB::table('cleaning_booking_rooms')
        ->whereIn('cleaning_booking_id', $bookings->pluck('id'))
        ->count())->toBe(24);
    expect(DB::table('cleaning_booking_special_services')
        ->whereIn('cleaning_booking_id', $bookings->pluck('id'))
        ->count())->toBe(8);
    expect(DB::table('cleaning_booking_sessions')
        ->whereIn('cleaning_booking_id', $bookings->pluck('id'))
        ->count())->toBe(32);
    expect(DB::table('cleaning_time_warnings')
        ->whereIn('booking_id', $bookings->pluck('id'))
        ->where('customer_response', 'extend_time')
        ->count())->toBe(4);

    $special = $bookings->firstWhere('booking_number', 'CLN-W1-JOURNEY-SPECIAL-WORKER_ASSIGNED');
    expect((float) $special->base_price)->toBe(
        (float) DB::table('cleaning_booking_special_services')
            ->where('cleaning_booking_id', $special->id)
            ->value('total_price'),
    );

    $overdue = $bookings->firstWhere('booking_number', 'CLN-W1-JOURNEY-HOURLY-IN_PROGRESS');
    expect($overdue->status)->toBe(CleaningBookingStatus::InProgress)
        ->and($overdue->booking_kind)->toBe('open_time')
        ->and($overdue->work_started_at->lt(now()->subHours(4)))->toBeTrue();
});

it('uses the Arabic Rana worker profile when both legacy demo profiles are present', function (): void {
    Worker::factory()->create([
        'user_id' => User::factory()->create(['email' => 'worker1@dllni.sy'])->id,
    ]);
    $rana = Worker::factory()->create([
        'user_id' => User::factory()->create(['email' => 'cleaning.worker@dllni.sy'])->id,
    ]);
    User::factory()->create(['email' => 'cleaning.customer@dllni.sy']);
    CleaningBillingPolicy::query()->create([
        'name' => 'Worker journey test',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
    DB::table('cleaning_special_services')->insert([
        'name' => 'غسيل الكنبايات بالبخار',
        'slug' => 'sofa-steam',
        'pricing_unit' => 'seat',
        'base_unit_price' => 35,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->seed(WorkerOrderJourneySeeder::class);
    $booking = CleaningBooking::query()->where('booking_number', 'CLN-W1-JOURNEY-ROOM-WORKER_ASSIGNED')->firstOrFail();
    expect($booking->worker_id)->toBe($rana->id)
        ->and($booking->preferred_worker_id)->toBe($rana->id);
});
