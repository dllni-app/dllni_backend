<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningFinancialSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningSpecialService;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $this->billingPolicy = CleaningBillingPolicy::first() ?? CleaningBillingPolicy::create([
        'name' => 'Default',
        'billing_mode' => 'actual_working_time',
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);
});

it('hides other customers cleaning booking lists and private detail while preserving own bookings', function (): void {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $owner->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'property_details' => ['private_note' => 'unrelated-customer-confidential'],
    ]);

    Sanctum::actingAs($stranger);
    $this->getJson('/api/v1/cleaning-bookings')->assertForbidden();
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertForbidden();

    Sanctum::actingAs($owner);
    $this->getJson('/api/v1/cleaning-bookings')->assertForbidden();
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertForbidden();
    $this->getJson("/api/v1/user/cleaning/orders/{$booking->id}")
        ->assertOk()->assertJsonPath('data.id', $booking->id);
});

it('keeps pending work discovery available to workers without exposing customer contact', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'worker_id' => null,
        'address_latitude' => 36.201,
        'address_longitude' => 37.155,
        'property_details' => ['address' => 'private street 5', 'private_note' => 'private instruction', 'bedrooms' => 2],
    ]);

    Sanctum::actingAs($workerUser);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertNotFound();

    $booking->update(['status' => CleaningBookingStatus::WorkerAssigned]);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertNotFound();
});

it('limits a worker list to own assigned work and eligible pending offers', function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        ['minimum_deposit_amount' => 0, 'restriction_threshold_percent' => 100,
            'trust_reject_after_accept_penalty' => 10, 'trust_minimum_for_dispatch' => 0],
    );
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => CleaningFinancialSetting::query()->orderBy('id')->value('id') ?? 1],
        ['default_commission_rate' => 10, 'commission_type' => 'percent',
            'commission_fixed_amount' => null, 'vat_rate' => 0, 'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0, 'travel_per_km' => 7500, 'travel_distance_start_point' => 'worker_home'],
    );
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 80,
        'is_active' => true,
        'is_suspended' => false,
        'home_address' => 'Worker Home',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
    ]);
    $otherWorker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 1000000,
        'debt_balance' => 0,
        'deposited_total' => 1000000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 1000000,
        'is_active' => true,
    ]);

    $ownedBooking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    $otherBooking = CleaningBooking::factory()->create([
        'worker_id' => $otherWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Completed,
    ]);
    $pendingBooking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => null,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'base_price' => 100000,
        'addons_total' => 0,
        'address_latitude' => 36.1795,
        'address_longitude' => 37.1082,
        'property_details' => ['bedrooms' => 2, 'address' => 'private street 5', 'private_note' => 'private info'],
        'cleaning_services' => ['تنظيف الغرف', 'تنظيف المطبخ'],
    ]);
    $catalog = CleaningSpecialService::query()->create([
        'name' => 'تنظيف مخصص', 'pricing_unit' => 'piece',
        'base_unit_price' => 100, 'estimated_duration_minutes' => 30, 'is_active' => true,
    ]);
    CleaningBookingSpecialService::query()->create([
        'cleaning_booking_id' => $pendingBooking->id,
        'cleaning_special_service_id' => $catalog->id,
        'service_name' => 'تنظيف مخصص', 'quantity' => 2,
        'pricing_unit' => 'piece', 'dirtiness_level' => 'heavy',
        'base_unit_price' => 100, 'price_multiplier' => 1, 'total_price' => 200,
        'notes' => 'Private customer note',
        'equipment_snapshot' => [['id' => 19, 'name' => 'جهاز بخار', 'internal' => 'secret']],
    ]);

    Sanctum::actingAs($workerUser);
    $response = $this->getJson('/api/v1/cleaning-bookings')->assertOk();
    $ids = array_column($response->json('data'), 'id');
    expect($ids)->toContain($ownedBooking->id)
        ->toContain($pendingBooking->id)
        ->not->toContain($otherBooking->id);

    $pending = collect($response->json('data'))->firstWhere('id', $pendingBooking->id);
    expect($pending)->not->toHaveKey('addressLatitude')
        ->not->toHaveKey('addressLongitude')
        ->not->toHaveKey('customer')
        ->not->toHaveKey('propertyDetails');
    expect($pending['cleaning_services'])->toBe(['تنظيف الغرف', 'تنظيف المطبخ']);
    expect(array_column($pending['services'], 'name'))->toBe(['تنظيف الغرف', 'تنظيف المطبخ']);
    expect($pending['specialServicesPreview'])->toBe([[
        'name' => 'تنظيف مخصص', 'quantity' => 2, 'pricingUnit' => 'piece',
        'dirtinessLevel' => 'heavy', 'equipmentNames' => ['جهاز بخار'],
    ]]);
    expect(json_encode($pending))->not->toContain('Private customer note')->not->toContain('secret');
});

it('preserves authorized administrative list and show access', function (): void {
    $admin = User::factory()->create();
    Role::findOrCreate('admin', 'web');
    $admin->assignRole('admin');
    $booking = CleaningBooking::factory()->create([
        'billing_policy_id' => $this->billingPolicy->id,
    ]);

    Sanctum::actingAs($admin);
    $this->getJson('/api/v1/cleaning-bookings')->assertOk()->assertJsonPath('data.0.id', $booking->id);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertOk();
});
