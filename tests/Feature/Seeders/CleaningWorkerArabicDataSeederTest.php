<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\CancellationPolicySeeder;
use Database\Seeders\CleaningWorkersSeeder;
use Modules\Cleaning\Database\Seeders\CleaningBillingPolicySeeder;
use Modules\Cleaning\Database\Seeders\CleaningWorkerExtensionScenarioSeeder;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Models\CleaningBooking;

beforeEach(function (): void {
    $this->seed(CleaningWorkersSeeder::class);
    $this->seed(CancellationPolicySeeder::class);
    $this->seed(CleaningBillingPolicySeeder::class);
    $this->seed(CleaningWorkerExtensionScenarioSeeder::class);
});

it('seeds pending extension requests for every known cleaning worker account', function (): void {
    foreach (['cleaning.worker@dllni.sy', 'cleaning.worker2@dllni.sy', 'cleaning.worker3@dllni.sy'] as $email) {
        $user = User::where('email', $email)->first();

        expect($user)->not->toBeNull();
        expect($user->worker)->not->toBeNull();

        $booking = CleaningBooking::query()
            ->where('worker_id', $user->worker->id)
            ->where('status', CleaningBookingStatus::TimeExtensionRequested->value)
            ->first();

        expect($booking)->not->toBeNull();
        expect($booking->timeWarnings()
            ->where('customer_response', CleaningTimeWarningResponse::ExtendTime->value)
            ->whereNull('worker_response')
            ->whereNull('worker_responded_at')
            ->where('additional_minutes', '>', 0)
            ->where('quoted_amount', '>', 0)
            ->exists()
        )->toBeTrue();
    }
});

it('keeps the extension scenario seeder idempotent', function (): void {
    $this->seed(CleaningWorkerExtensionScenarioSeeder::class);

    foreach (['CLN-AR-W1', 'CLN-AR-W2', 'CLN-AR-W3'] as $prefix) {
        expect(CleaningBooking::where('booking_number', $prefix.'-EXT-0001')->count())->toBe(1);
        expect(CleaningBooking::where('booking_number', $prefix.'-INPROG-0001')->count())->toBe(1);
        expect(CleaningBooking::where('booking_number', $prefix.'-ASSIGNED-0001')->count())->toBe(1);
        expect(CleaningBooking::where('booking_number', $prefix.'-COMPLETED-0001')->count())->toBe(1);
        expect(CleaningBooking::where('booking_number', $prefix.'-PENDING-0001')->count())->toBe(1);
    }
});
