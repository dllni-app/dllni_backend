<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningFinancialSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

it('traces full HTTP first business SQL and transaction depth for both worker session acceptance routes', function (): void {
    expect(DB::connection()->getDriverName())->toBe('sqlite');

    CleaningDepositSetting::query()->updateOrCreate(['id' => 1], [
        'minimum_deposit_amount' => 0,
        'restriction_threshold_percent' => 100,
        'allowance_warning_threshold_percent' => 10,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 50,
    ]);
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 10,
        'commission_type' => 'percent',
        'vat_rate' => 0,
        'travel_markup_type' => 'fixed',
        'travel_markup_value' => 0,
        'travel_per_km' => 0,
        'travel_distance_start_point' => 'worker_home',
    ]);
    $user = User::factory()->create(['is_active' => true]);
    $hours = [];
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $hours[$day] = ['available' => true, 'data' => [['00:00' => '23:59']]];
    }
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'gender' => 'male',
        'trust_score' => 90,
        'is_active' => true,
        'is_suspended' => false,
        'home_address' => 'Worker Home',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
        'default_working_hours' => $hours,
        'security_deposit_status' => 'active',
    ]);
    CleaningWorkerDeposit::query()->updateOrCreate(['worker_id' => $worker->id], [
        'current_balance' => 100000,
        'debt_balance' => 0,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
        'is_active' => true,
    ]);
    Sanctum::actingAs($user);

    $outcomes = [];
    $trace = [];
    $capturing = false;
    DB::listen(static function ($event) use (&$trace, &$capturing): void {
        if ($capturing) {
            $trace[] = [
                'sql' => $event->sql,
                'depth' => $event->connection->transactionLevel(),
            ];
        }
    });
    foreach (['accept-all', 'accept-selected'] as $mode) {
        $booking = CleaningBooking::factory()->create([
            'status' => 'pending',
            'worker_id' => null,
            'preferred_worker_id' => null,
            'base_price' => 3000,
            'addons_total' => 0,
            'property_type' => 'villa',
            'number_of_workers' => 1,
            'scheduled_date' => now()->addDays($mode === 'accept-all' ? 1 : 3)->toDateString(),
            'scheduled_time' => '10:00',
            'gender_preference' => 'any',
            'neighborhood_id' => null,
            'address_latitude' => 36.1795,
            'address_longitude' => 37.1082,
        ]);
        $session = CleaningBookingSession::query()->create([
            'cleaning_booking_id' => $booking->id,
            'sequence' => 1,
            'session_type' => 'event_day',
            'calculation_mode' => 'hours',
            'scheduled_date' => now()->addDays($mode === 'accept-all' ? 1 : 3)->toDateString(),
            'scheduled_time' => '10:00',
            'duration_hours' => 1,
            'required_workers' => 1,
            'coverage_status' => 'searching',
            'status' => 'scheduled',
            'base_price' => 3000,
            'admin_margin_amount' => 300,
            'total_price' => 3300,
            'is_pricing_final' => false,
        ]);

        $trace = [];
        $capturing = true;
        $baselineDepth = DB::connection()->transactionLevel();
        $response = $this->postJson(
            "/api/v1/cleaning-bookings/{$booking->id}/sessions/{$mode}",
            $mode === 'accept-selected' ? ['sessionIds' => [$session->id]] : [],
        );
        $capturing = false;
        $response->assertOk()->assertJsonPath('data.acceptance.acceptedSessionIds', [$session->id]);

        $firstBusinessIndex = array_key_first(array_filter(
            $trace,
            static fn (array $query): bool => preg_match('/\b(?:cleaning_bookings|cleaning_booking_sessions|cleaning_worker_deposits|workers)\b/i', $query['sql']) === 1,
        ));
        expect($trace)->not->toBeEmpty()
            ->and($firstBusinessIndex)->not->toBeNull()
            ->and($trace[$firstBusinessIndex]['depth'])->toBe($baselineDepth)
            ->and(collect($trace)->contains(static fn (array $query): bool => $query['depth'] > $baselineDepth))->toBeTrue()
            ->and(DB::connection()->transactionLevel())->toBe($baselineDepth);

        $outcomes[$mode] = [
            'http_status' => $response->status(),
            'baseline_depth' => $baselineDepth,
            'first_business_index' => $firstBusinessIndex,
            'first_business_sql' => $trace[$firstBusinessIndex],
            'business_sql_in_root_depth' => collect($trace)->filter(
                static fn (array $query): bool => $query['depth'] > $baselineDepth,
            )->take(4)->values()->all(),
            'observed_depths' => collect($trace)->pluck('depth')->unique()->sort()->values()->all(),
            'query_count' => count($trace),
        ];
    }

    fwrite(STDOUT, '\nP3_RUN07_HTTP_SQL_TRACE='.json_encode($outcomes, JSON_UNESCAPED_SLASHES).'\n');
});
