<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

it('returns pending preferred worker bookings when worker app sends flat status query parameter', function (): void {
    $workerUser = User::factory()->create(['email' => 'preferred-worker-dispatch@example.com']);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'is_suspended' => false,
    ]);
    $otherWorker = Worker::factory()->create();

    Sanctum::actingAs($workerUser);

    $billingPolicy = CleaningBillingPolicy::query()->first() ?? CleaningBillingPolicy::query()->create([
        'name' => 'Default',
        'billing_mode' => CleaningBillingMode::ActualWorkingTime->value,
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $preferredPending = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
    ]);

    CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => $worker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Completed,
    ]);

    CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => $otherWorker->id,
        'billing_policy_id' => $billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&status=pending&sort=-createdAt');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect((int) $response->json('data.0.id'))->toBe($preferredPending->id);
    expect($response->json('data.0.status'))->toBe(CleaningBookingStatus::Pending->value);
    expect($response->json('data.0.preferredWorker.id'))->toBe($worker->id);
});
