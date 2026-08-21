<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningFinancialSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingTeamService;

it('rechecks worker commission capacity atomically when accepting a booking', function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'restriction_threshold_percent' => 100,
            'allowance_warning_threshold_percent' => 10,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 50,
        ],
    );

    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => CleaningFinancialSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'default_commission_rate' => 10.00,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'vat_rate' => 0.00,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0.00,
            'travel_per_km' => 0.00,
            'travel_distance_start_point' => 'worker_home',
        ],
    );

    $user = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 80,
        'is_active' => true,
        'is_suspended' => false,
        'home_address' => 'Worker Home',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
    ]);

    CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => 1000,
            'debt_balance' => 0,
            'deposited_total' => 1000,
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 1000,
            'is_active' => true,
        ],
    );

    $alreadyAcceptedBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'base_price' => 8000,
        'addons_total' => 0,
        'property_type' => 'event_assistance',
        'number_of_workers' => 1,
        'scheduled_date' => now()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'gender_preference' => 'any',
        'address_latitude' => 36.1795,
        'address_longitude' => 37.1082,
    ]);

    $alreadyAcceptedBooking->workerAssignments()->create([
        'worker_id' => $worker->id,
        'status' => 'accepted_waiting_for_order_start',
        'accepted_at' => now(),
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 7200,
        'travel_fee' => 0,
        'admin_margin_amount' => 800,
        'worker_amount' => 7200,
        'currency' => 'SYP',
    ]);

    $newBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'base_price' => 4000,
        'addons_total' => 0,
        'property_type' => 'event_assistance',
        'number_of_workers' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => now()->addHour()->format('H:i'),
        'gender_preference' => 'any',
        'address_latitude' => 36.1795,
        'address_longitude' => 37.1082,
    ]);

    expect(fn () => app(CleaningBookingTeamService::class)->acceptWorker($newBooking, $worker))
        ->toThrow(
            InvalidArgumentException::class,
            'The available deposit or remaining allowance does not cover this booking platform commission.',
        );

    expect($newBooking->workerAssignments()->where('worker_id', $worker->id)->exists())->toBeFalse();
});
